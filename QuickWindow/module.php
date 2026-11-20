<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/QuickSwitchPresentation.php';

class QuickWindow extends IPSModule
{
    use QuickSwitchPresentation;

    // Eigene Icons je Zustand; leer = Standard-Icon des Typs (Fenster bzw. Tür)
    private const ICON_COLUMNS = ['closed' => 'iconClosed', 'open' => 'iconOpen', 'tilted' => 'iconTilted'];
    private const DEFAULT_ICONS = [
        'window' => ['closed' => 'window-frame', 'open' => 'window-frame-open', 'tilted' => 'window-frame-open'],
        'door'   => ['closed' => 'door-closed', 'open' => 'door-open', 'tilted' => 'door-open']
    ];

    // Griffarten: Werte der Griffstellung => Zustand
    private const HANDLE_TYPE_DOOR_STATE = 'doorState';
    private const HANDLE_TYPE_HOMEMATIC = 'homematic';
    private const POSITION_STATES = [
        // Matter DoorLock_DoorState (z. B. Siegenia): 0 geöffnet, 1 geschlossen, 2 blockiert, 3 gewaltsam geöffnet, 4 unbekannter Fehler, 5 gekippt
        self::HANDLE_TYPE_DOOR_STATE => [0 => 'open', 1 => 'closed', 2 => 'fault', 3 => 'forced', 4 => 'fault', 5 => 'tilted'],
        // Homematic Drehgriffkontakt (STATE): 0 geschlossen, 1 gekippt, 2 offen
        self::HANDLE_TYPE_HOMEMATIC => [0 => 'closed', 1 => 'tilted', 2 => 'open']
    ];

    // Werte des Verriegelungsstatus (Matter DoorLock_LockState)
    private const LOCK_NOT_FULLY_LOCKED = 0;
    private const LOCK_LOCKED = 1;
    private const LOCK_UNLOCKED = 2;
    private const LOCK_UNLATCHED = 3;

    // Werte der Aktionsvariable (Matter DoorLock_LockUnlockUnbolt)
    private const COMMAND_OPEN = 0;
    private const COMMAND_UNLOCK = 1;
    private const COMMAND_LOCK = 2;
    private const COMMAND_IDENTS = ['Open' => self::COMMAND_OPEN, 'Unlock' => self::COMMAND_UNLOCK, 'Lock' => self::COMMAND_LOCK];

    private const STATUS_INVALID_OPENING = 201;
    private const STATUS_INVALID_LIST = 202;
    private const STATUS_INVALID_HANDLE = 203;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('Openings', '[]');
        $this->RegisterPropertyString('Handles', '[]');
        $this->RegisterPropertyBoolean('ShowStatus', true);
        $this->RegisterPropertyBoolean('ShowSummary', true);

        $this->RegisterMessage(0, IPS_KERNELSTARTED);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $this->SetVisualizationType(1);

        // Verknüpfte Variablen erst prüfen, wenn der Kernel bereit ist; sonst übernimmt das IPS_KERNELSTARTED.
        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }

        $openings = $this->readOpenings();
        $handles = $this->readHandles();
        $this->registerObjectMessages($openings ?? [], $handles ?? []);
        $this->SetStatus($this->configurationStatus($openings, $handles));
        $this->updateVisualization();
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data): void
    {
        switch ($Message) {
            case IPS_KERNELSTARTED:
                $this->ApplyChanges();
                break;

            case VM_UPDATE:
                $this->updateStates();
                break;

            case VM_DELETE:
                $this->SetStatus($this->configurationStatus($this->readOpenings(), $this->readHandles()));
                $this->updateVisualization();
                break;
        }
    }

    public function GetVisualizationTile(): string
    {
        $template = @file_get_contents(__DIR__ . '/module.html');
        if (!is_string($template)) {
            $this->SendDebug('Visualization', 'HTML-Vorlage konnte nicht geladen werden', 0);
            $this->LogMessage($this->Translate('The tile template could not be loaded.'), KL_ERROR);
            return '';
        }

        try {
            $initialState = json_encode(
                $this->buildFullState(),
                JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR
            );
        } catch (Throwable $e) {
            $this->SendDebug('Visualization', 'Initialzustand konnte nicht erstellt werden: ' . $e->getMessage(), 0);
            $initialState = '{"type":"full","showStatus":false,"showSummary":false,"summary":"","openings":[]}';
        }

        return str_replace('__QUICKWINDOW_INITIAL_STATE__', $initialState, $template);
    }

    public function RequestAction($Ident, $Value): void
    {
        switch ($Ident) {
            case 'LockAll':
                $this->lockAll((int) $Value);
                return;
            case 'Toggle':
                $this->toggleLock((int) $Value);
                return;
        }
        if (array_key_exists($Ident, self::COMMAND_IDENTS)) {
            $this->sendCommand((int) $Value, self::COMMAND_IDENTS[$Ident]);
            return;
        }
        throw new Exception(sprintf($this->Translate('Unknown action: %s'), $Ident));
    }

    // --------- Konfiguration ---------

    /**
     * @return array<int, array{contactVariableId: int, invert: bool, kind: string, label: string, showLabel: bool, icons: array<string, string>}>|null null bei unlesbarer Liste
     */
    private function readOpenings(): ?array
    {
        $rows = json_decode($this->ReadPropertyString('Openings'), true);
        if (!is_array($rows)) {
            $this->SendDebug('Openings', 'Liste der Fenster und Türen ist kein gültiges JSON', 0);
            return null;
        }

        $openings = [];
        foreach (array_values($rows) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $icons = [];
            foreach (self::ICON_COLUMNS as $key => $column) {
                $icon = is_string($row[$column] ?? null) ? trim($row[$column]) : '';
                $icons[$key] = preg_match('/^[a-z0-9-]+$/', $icon) === 1 ? $icon : '';
            }
            $openings[] = [
                'contactVariableId' => is_int($row['contactVariable'] ?? null) ? $row['contactVariable'] : 0,
                'invert'            => ($row['invert'] ?? false) === true,
                'kind'              => ($row['kind'] ?? 'window') === 'door' ? 'door' : 'window',
                'label'             => is_string($row['label'] ?? null) ? trim($row['label']) : '',
                'showLabel'         => ($row['showLabel'] ?? true) !== false,
                'icons'             => $icons
            ];
        }
        return $openings;
    }

    /**
     * Griffe und Schlösser: Griffstellung und Verriegelung sind jeweils optional, mindestens eines von beiden ist gesetzt.
     *
     * @return array<int, array{contactVariableId: int, handleType: string, positionVariableId: int, lockStatusVariableId: int, lockActionVariableId: int, label: string, confirmOpen: bool}>|null null bei unlesbarer Liste
     */
    private function readHandles(): ?array
    {
        $rows = json_decode($this->ReadPropertyString('Handles'), true);
        if (!is_array($rows)) {
            $this->SendDebug('Handles', 'Liste der Fenstergriffe ist kein gültiges JSON', 0);
            return null;
        }

        $handles = [];
        foreach (array_values($rows) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $handles[] = [
                'contactVariableId'    => is_int($row['contactVariable'] ?? null) ? $row['contactVariable'] : 0,
                'handleType'           => ($row['handleType'] ?? '') === self::HANDLE_TYPE_HOMEMATIC ? self::HANDLE_TYPE_HOMEMATIC : self::HANDLE_TYPE_DOOR_STATE,
                'positionVariableId'   => is_int($row['positionVariable'] ?? null) ? $row['positionVariable'] : 0,
                'lockStatusVariableId' => is_int($row['lockStatusVariable'] ?? null) ? $row['lockStatusVariable'] : 0,
                'lockActionVariableId' => is_int($row['lockActionVariable'] ?? null) ? $row['lockActionVariable'] : 0,
                'label'                => is_string($row['label'] ?? null) ? trim($row['label']) : '',
                'confirmOpen'          => ($row['confirmOpen'] ?? false) === true
            ];
        }
        return $handles;
    }

    private function isVariableOfType(int $variableId, int $type): bool
    {
        return $variableId > 0
            && IPS_VariableExists($variableId)
            && IPS_GetVariable($variableId)['VariableType'] === $type;
    }

    private function isValidOpening(array $opening): bool
    {
        return $this->isVariableOfType($opening['contactVariableId'], VARIABLETYPE_BOOLEAN);
    }

    private function hasPosition(array $handle): bool
    {
        return $handle['positionVariableId'] > 0;
    }

    // Eintrag mit Verriegelung: Status- oder Aktionsvariable gesetzt (gültig nur mit beiden).
    private function isLockable(array $handle): bool
    {
        return $handle['lockStatusVariableId'] > 0 || $handle['lockActionVariableId'] > 0;
    }

    private function isValidHandle(array $handle, array $openings): bool
    {
        if (!in_array($handle['contactVariableId'], array_column($openings, 'contactVariableId'), true)) {
            return false;
        }
        if (!$this->hasPosition($handle) && !$this->isLockable($handle)) {
            return false;
        }
        if ($this->hasPosition($handle) && !$this->isVariableOfType($handle['positionVariableId'], VARIABLETYPE_INTEGER)) {
            return false;
        }
        if (!$this->isLockable($handle)) {
            return true;
        }
        return $this->isVariableOfType($handle['lockStatusVariableId'], VARIABLETYPE_INTEGER)
            && $this->isVariableOfType($handle['lockActionVariableId'], VARIABLETYPE_INTEGER)
            && HasAction($handle['lockActionVariableId']);
    }

    private function configurationStatus(?array $openings, ?array $handles): int
    {
        if ($openings === null || $handles === null) {
            return self::STATUS_INVALID_LIST;
        }
        if ($openings === []) {
            return IS_INACTIVE;
        }
        foreach ($openings as $opening) {
            if (!$this->isValidOpening($opening)) {
                $this->SendDebug('Openings', 'Ungültiger Eintrag mit Kontakt ' . $opening['contactVariableId'], 0);
                return self::STATUS_INVALID_OPENING;
            }
        }
        foreach ($handles as $handle) {
            if (!$this->isValidHandle($handle, $openings)) {
                $this->SendDebug('Handles', 'Ungültiger Griff/Schloss (Griffstellung ' . $handle['positionVariableId'] . ', Aktion ' . $handle['lockActionVariableId'] . ') für Kontakt ' . $handle['contactVariableId'], 0);
                return self::STATUS_INVALID_HANDLE;
            }
        }
        return IS_ACTIVE;
    }

    // Meldet nur die Nachrichten der aktuell verknüpften Variablen an und alle übrigen ab.
    private function registerObjectMessages(array $openings, array $handles): void
    {
        $wanted = [];
        $watch = static function (int $variableId, bool $updates) use (&$wanted): void
        {
            if ($variableId > 0 && IPS_VariableExists($variableId)) {
                $wanted[$variableId][VM_DELETE] = true;
                if ($updates) {
                    $wanted[$variableId][VM_UPDATE] = true;
                }
            }
        };
        foreach ($openings as $opening) {
            $watch($opening['contactVariableId'], true);
        }
        foreach ($handles as $handle) {
            $watch($handle['positionVariableId'], true);
            $watch($handle['lockStatusVariableId'], true);
            $watch($handle['lockActionVariableId'], false);
        }

        foreach ($this->GetMessageList() as $senderId => $messages) {
            if ($senderId === 0) {
                continue;
            }
            foreach ($messages as $message) {
                if (!isset($wanted[$senderId][$message])) {
                    $this->UnregisterMessage($senderId, $message);
                }
            }
        }
        foreach ($wanted as $senderId => $messages) {
            foreach (array_keys($messages) as $message) {
                $this->RegisterMessage($senderId, $message);
            }
        }
    }

    /**
     * Griffe je Öffnung (Kontaktvariable), in der Reihenfolge der Liste.
     *
     * @return array<int, array<int, array>>
     */
    private function handlesByOpening(array $handles): array
    {
        $result = [];
        foreach ($handles as $handle) {
            $result[$handle['contactVariableId']][] = $handle;
        }
        return $result;
    }

    private function findOpening(int $contactVariableId): ?array
    {
        foreach ($this->readOpenings() ?? [] as $opening) {
            if ($opening['contactVariableId'] === $contactVariableId) {
                return $opening;
            }
        }
        return null;
    }

    // --------- Befehle ---------

    /**
     * Befehle, die die Aktionsvariable laut Darstellung anbietet, mit Beschriftung, Icon und Farbe. Ohne lesbare
     * Darstellung gelten Öffnen und Verriegeln (wie bei QuickLock).
     *
     * @return array<int, array{caption: string, icon: string, color: string}>
     */
    private function commandOptions(int $actionVariableId): array
    {
        $defaults = [
            self::COMMAND_OPEN   => ['caption' => $this->Translate('Open (command)'), 'icon' => 'lock-open', 'color' => ''],
            self::COMMAND_UNLOCK => ['caption' => $this->Translate('Unlock'), 'icon' => 'lock-open', 'color' => ''],
            self::COMMAND_LOCK   => ['caption' => $this->Translate('Lock'), 'icon' => 'lock', 'color' => '']
        ];
        $commands = [];
        foreach ($this->enumerationOptions($actionVariableId) as $option) {
            $value = (int) $option['value'];
            if (!isset($defaults[$value]) || isset($commands[$value])) {
                continue;
            }
            $commands[$value] = [
                'caption' => $option['caption'] !== '' ? $option['caption'] : $defaults[$value]['caption'],
                'icon'    => $option['icon'] !== '' ? $option['icon'] : $defaults[$value]['icon'],
                'color'   => $option['color']
            ];
        }
        if (!isset($commands[self::COMMAND_LOCK])) {
            $commands[self::COMMAND_LOCK] = $defaults[self::COMMAND_LOCK];
        }
        if (!isset($commands[self::COMMAND_OPEN]) && !isset($commands[self::COMMAND_UNLOCK])) {
            $commands[self::COMMAND_OPEN] = $defaults[self::COMMAND_OPEN];
        }
        ksort($commands);
        return $commands;
    }

    // Aufschließen: Entriegeln, wenn angeboten (Schloss mit Knauf), sonst Öffnen.
    private function unlockCommand(array $commands): int
    {
        return isset($commands[self::COMMAND_UNLOCK]) ? self::COMMAND_UNLOCK : self::COMMAND_OPEN;
    }

    // Gültiger Eintrag mit Verriegelung, der zu dieser Instanz gehört
    private function findLockableHandle(int $lockActionVariableId): ?array
    {
        $openings = $this->readOpenings() ?? [];
        foreach ($this->readHandles() ?? [] as $handle) {
            if ($handle['lockActionVariableId'] === $lockActionVariableId && $this->isLockable($handle) && $this->isValidHandle($handle, $openings)) {
                return $handle;
            }
        }
        return null;
    }

    /**
     * Verriegeln ist nur bei geschlossenem Griff erlaubt; ein Schloss ohne Griffstellung (z. B. Türschloss) nur bei
     * geschlossenem Kontakt.
     */
    private function lockAllowed(array $handle, ?array $opening): bool
    {
        if ($this->hasPosition($handle)) {
            return $this->positionState($handle['handleType'], GetValueInteger($handle['positionVariableId'])) === 'closed';
        }
        return $opening !== null && $this->isValidOpening($opening) && !$this->contactOpen($opening);
    }

    private function toggleLock(int $lockActionVariableId): void
    {
        $handle = $this->findLockableHandle($lockActionVariableId);
        if ($handle === null) {
            $this->SendDebug('Toggle', 'Schloss ist nicht verknüpft oder ungültig: ' . $lockActionVariableId, 0);
            return;
        }
        $command = GetValueInteger($handle['lockStatusVariableId']) === self::LOCK_LOCKED
            ? $this->unlockCommand($this->commandOptions($lockActionVariableId))
            : self::COMMAND_LOCK;
        $this->sendCommand($lockActionVariableId, $command);
    }

    private function sendCommand(int $lockActionVariableId, int $command): bool
    {
        $handle = $this->findLockableHandle($lockActionVariableId);
        if ($handle === null) {
            $this->SendDebug('Command', 'Schloss ist nicht verknüpft, ohne Verriegelung oder ungültig: ' . $lockActionVariableId, 0);
            return false;
        }
        if (!isset($this->commandOptions($lockActionVariableId)[$command])) {
            $this->SendDebug('Command', 'Befehl ' . $command . ' wird von ' . $lockActionVariableId . ' nicht angeboten', 0);
            return false;
        }
        // Ein Stellmotor soll einen offenen oder gekippten Flügel bzw. eine offene Tür nicht verriegeln.
        if ($command === self::COMMAND_LOCK && !$this->lockAllowed($handle, $this->findOpening($handle['contactVariableId']))) {
            $this->SendDebug('Command', 'Verriegeln abgelehnt, Griff bzw. Kontakt ist nicht geschlossen: ' . $lockActionVariableId, 0);
            $this->sendToTile(['type' => 'failed', 'id' => $lockActionVariableId]);
            return false;
        }

        try {
            $success = RequestAction($lockActionVariableId, $command);
        } catch (Throwable $e) {
            $this->SendDebug('Command', 'Ausnahme bei Variable ' . $lockActionVariableId . ': ' . $e->getMessage(), 0);
            $success = false;
        }
        if (!$success) {
            $this->LogMessage(sprintf($this->Translate('Command %d for variable %d failed.'), $command, $lockActionVariableId), KL_WARNING);
            $this->sendToTile(['type' => 'failed', 'id' => $lockActionVariableId]);
            return false;
        }
        $this->SendDebug('Command', 'Befehl ' . $command . ' an Variable ' . $lockActionVariableId . ' gesendet', 0);
        return true;
    }

    // Verriegelt alle verriegelbaren, noch nicht verriegelten Einträge einer Öffnung; ein Fehler stoppt die übrigen nicht.
    private function lockAll(int $contactVariableId): void
    {
        $openings = $this->readOpenings() ?? [];
        $opening = null;
        foreach ($openings as $candidate) {
            if ($candidate['contactVariableId'] === $contactVariableId) {
                $opening = $candidate;
            }
        }
        if ($opening === null) {
            $this->SendDebug('LockAll', 'Öffnung ist nicht verknüpft: ' . $contactVariableId, 0);
            return;
        }
        foreach ($this->readHandles() ?? [] as $handle) {
            if ($handle['contactVariableId'] !== $contactVariableId || !$this->isLockable($handle) || !$this->isValidHandle($handle, $openings)) {
                continue;
            }
            if ($this->lockAllowed($handle, $opening) && GetValueInteger($handle['lockStatusVariableId']) !== self::LOCK_LOCKED) {
                $this->sendCommand($handle['lockActionVariableId'], self::COMMAND_LOCK);
            }
        }
    }

    // --------- Zustände ---------

    private function contactOpen(array $opening): bool
    {
        return GetValueBoolean($opening['contactVariableId']) !== $opening['invert'];
    }

    private function formattedText(int $variableId, int $value, string $fallback): string
    {
        // Text aus der Darstellung der Variable; ohne Darstellung liefert Symcon nur die Zahl.
        try {
            $formatted = trim((string) GetValueFormatted($variableId));
        } catch (Throwable $e) {
            $formatted = '';
        }
        return $formatted !== '' && $formatted !== '-' && $formatted !== (string) $value ? $formatted : $fallback;
    }

    private function positionState(string $handleType, int $position): string
    {
        return self::POSITION_STATES[$handleType][$position] ?? 'fault';
    }

    private function positionFallbackText(string $handleType, int $position): string
    {
        if ($handleType === self::HANDLE_TYPE_DOOR_STATE) {
            if ($position === 2) {
                return $this->Translate('Jammed');
            }
            if ($position === 4) {
                return $this->Translate('Unknown error');
            }
        }
        return match ($this->positionState($handleType, $position)) {
            'open'   => $this->Translate('Open'),
            'closed' => $this->Translate('Closed'),
            'tilted' => $this->Translate('Tilted'),
            'forced' => $this->Translate('Forced open'),
            default  => $this->Translate('Unknown')
        };
    }

    private function lockFallbackText(int $status): string
    {
        return match ($status) {
            self::LOCK_NOT_FULLY_LOCKED => $this->Translate('Not fully locked'),
            self::LOCK_LOCKED           => $this->Translate('Locked'),
            self::LOCK_UNLOCKED         => $this->Translate('Unlocked'),
            self::LOCK_UNLATCHED        => $this->Translate('Unlatched'),
            default                     => $this->Translate('Unknown')
        };
    }

    // Veränderlicher Teil eines Griffs bzw. Schlosses
    private function handleState(array $handle, bool $valid, ?array $opening): array
    {
        $state = [
            'id'            => $handle['positionVariableId'] > 0 ? $handle['positionVariableId'] : $handle['lockActionVariableId'],
            'available'     => $valid,
            'hasPosition'   => $this->hasPosition($handle),
            'position'      => null,
            'positionState' => 'unknown',
            'positionText'  => '',
            'lockable'      => $valid && $this->isLockable($handle),
            'lockStatus'    => null,
            'lockText'      => '',
            'lockAllowed'   => false
        ];
        if (!$valid) {
            return $state;
        }
        if ($state['hasPosition']) {
            $position = GetValueInteger($handle['positionVariableId']);
            $state['position'] = $position;
            $state['positionState'] = $this->positionState($handle['handleType'], $position);
            $state['positionText'] = $this->formattedText($handle['positionVariableId'], $position, $this->positionFallbackText($handle['handleType'], $position));
        }
        if ($state['lockable']) {
            $lockStatus = GetValueInteger($handle['lockStatusVariableId']);
            $state['lockStatus'] = $lockStatus;
            $state['lockText'] = $this->formattedText($handle['lockStatusVariableId'], $lockStatus, $this->lockFallbackText($lockStatus));
            $state['lockAllowed'] = $this->lockAllowed($handle, $opening);
        }
        return $state;
    }

    /**
     * Gesamtzustand einer Öffnung: maßgeblich ist der Reed-Kontakt. Geschlossen ist geschlossen; nur bei offenem Kontakt
     * präzisieren die Griffe den Zustand (gewaltsam geöffnet > offen > gekippt). Schlösser zeigen ihren Zustand nur
     * an ihren eigenen Buttons.
     */
    private function openingStatus(bool $contactOpen, array $handleStates): string
    {
        if (!$contactOpen) {
            return 'closed';
        }
        $positions = [];
        foreach ($handleStates as $handle) {
            if ($handle['available'] === true && $handle['hasPosition']) {
                $positions[] = $handle['positionState'];
            }
        }
        foreach (['forced', 'open', 'tilted'] as $status) {
            if (in_array($status, $positions, true)) {
                return $status;
            }
        }
        return 'open';
    }

    private function openingText(string $status): string
    {
        return match ($status) {
            'forced' => $this->Translate('Forced open'),
            'open'   => $this->Translate('Open'),
            'tilted' => $this->Translate('Tilted'),
            'closed' => $this->Translate('Closed'),
            default  => $this->Translate('Unknown')
        };
    }

    private function openingIcon(array $opening, string $status): string
    {
        if ($status === 'forced') {
            return 'triangle-exclamation';
        }
        $key = match ($status) {
            'open'   => 'open',
            'tilted' => 'tilted',
            default  => 'closed'
        };
        if ($opening['icons'][$key] !== '') {
            return $opening['icons'][$key];
        }
        // Gekippt ohne eigenes Icon nutzt das eigene Icon für offen.
        if ($key === 'tilted' && $opening['icons']['open'] !== '') {
            return $opening['icons']['open'];
        }
        return self::DEFAULT_ICONS[$opening['kind']][$key];
    }

    // Veränderlicher Teil einer Öffnung mit ihren Griffen und Schlössern
    private function openingState(array $opening, array $handles, array $openings): array
    {
        $valid = $this->isValidOpening($opening);
        $handleStates = [];
        foreach ($handles as $handle) {
            $handleStates[] = $this->handleState($handle, $valid && $this->isValidHandle($handle, $openings), $opening);
        }
        $state = [
            'available' => $valid,
            'state'     => 'unknown',
            'text'      => '',
            'icon'      => self::DEFAULT_ICONS[$opening['kind']]['closed'],
            'handles'   => $handleStates
        ];
        if (!$valid) {
            return $state;
        }
        $status = $this->openingStatus($this->contactOpen($opening), $handleStates);
        return array_merge($state, [
            'state' => $status,
            'text'  => $this->openingText($status),
            'icon'  => $this->openingIcon($opening, $status)
        ]);
    }

    private function summaryText(array $openingStates): string
    {
        $counts = ['open' => 0, 'tilted' => 0];
        $available = 0;
        foreach ($openingStates as $state) {
            if ($state['available'] !== true) {
                continue;
            }
            $available++;
            $key = $state['state'] === 'forced' ? 'open' : $state['state'];
            if (isset($counts[$key])) {
                $counts[$key]++;
            }
        }
        if ($available === 0) {
            return '';
        }
        $parts = [];
        if ($counts['open'] > 0) {
            $parts[] = sprintf($this->Translate('%d open'), $counts['open']);
        }
        if ($counts['tilted'] > 0) {
            $parts[] = sprintf($this->Translate('%d tilted'), $counts['tilted']);
        }
        return $parts === [] ? $this->Translate('All closed') : implode(' · ', $parts);
    }

    // --------- Kachel ---------

    private function defaultLabel(int $variableId): string
    {
        if (!IPS_VariableExists($variableId)) {
            return '';
        }
        // Sensorvariablen heißen oft nur "Kontakt" bzw. "Türschloss"; der Gerätename ist aussagekräftiger.
        $parentId = IPS_GetParent($variableId);
        return $parentId > 0 && IPS_InstanceExists($parentId) ? IPS_GetName($parentId) : IPS_GetName($variableId);
    }

    private function buildOpeningStates(array $openings, array $handles): array
    {
        $byOpening = $this->handlesByOpening($handles);
        $states = [];
        foreach ($openings as $opening) {
            $states[] = $this->openingState($opening, $byOpening[$opening['contactVariableId']] ?? [], $openings);
        }
        return $states;
    }

    private function commandForTile(array $commands, int $value): ?array
    {
        return isset($commands[$value]) ? ['value' => $value] + $commands[$value] : null;
    }

    private function buildFullState(): array
    {
        $openings = $this->readOpenings() ?? [];
        $handles = $this->readHandles() ?? [];
        $byOpening = $this->handlesByOpening($handles);
        $states = $this->buildOpeningStates($openings, $handles);

        $tiles = [];
        foreach ($openings as $index => $opening) {
            $tile = $states[$index];
            $tile['id'] = $opening['contactVariableId'];
            $tile['kind'] = $opening['kind'];
            $tile['label'] = $opening['label'] !== '' ? $opening['label'] : $this->defaultLabel($opening['contactVariableId']);
            $tile['showLabel'] = $opening['showLabel'];
            foreach ($byOpening[$opening['contactVariableId']] ?? [] as $handleIndex => $handle) {
                $commands = $tile['handles'][$handleIndex]['lockable'] ? $this->commandOptions($handle['lockActionVariableId']) : [];
                $unlock = $commands !== [] ? $this->unlockCommand($commands) : null;
                $tile['handles'][$handleIndex] += [
                    'label'       => $handle['label'] !== '' ? $handle['label'] : $this->defaultLabel($handle['positionVariableId'] > 0 ? $handle['positionVariableId'] : $handle['lockActionVariableId']),
                    'lockId'      => $handle['lockActionVariableId'],
                    'confirmOpen' => $handle['confirmOpen'],
                    'commands'    => [
                        'unlock' => $unlock !== null ? $this->commandForTile($commands, $unlock) : null,
                        // Öffnen zusätzlich, wenn es neben Entriegeln angeboten wird (Falle ziehen)
                        'open'   => $unlock === self::COMMAND_UNLOCK ? $this->commandForTile($commands, self::COMMAND_OPEN) : null,
                        'lock'   => $this->commandForTile($commands, self::COMMAND_LOCK)
                    ]
                ];
            }
            $tiles[] = $tile;
        }

        return [
            'type'        => 'full',
            'showStatus'  => $this->ReadPropertyBoolean('ShowStatus'),
            'showSummary' => $this->ReadPropertyBoolean('ShowSummary'),
            'summary'     => $this->summaryText($states),
            'openings'    => $tiles
        ];
    }

    private function buildStates(): array
    {
        $states = $this->buildOpeningStates($this->readOpenings() ?? [], $this->readHandles() ?? []);
        return ['type' => 'states', 'summary' => $this->summaryText($states), 'openings' => $states];
    }

    private function updateVisualization(): void
    {
        $this->sendToTile($this->buildFullState());
    }

    private function updateStates(): void
    {
        $this->sendToTile($this->buildStates());
    }

    private function sendToTile(array $message): void
    {
        try {
            $this->UpdateVisualizationValue(json_encode($message, JSON_THROW_ON_ERROR));
        } catch (Throwable $e) {
            $this->SendDebug('Visualization', 'Kachelzustand konnte nicht übertragen werden: ' . $e->getMessage(), 0);
        }
    }
}
