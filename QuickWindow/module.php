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

    // Werte der Griffstellung (Matter DoorLock_DoorState)
    private const POSITION_OPEN = 0;
    private const POSITION_CLOSED = 1;
    private const POSITION_JAMMED = 2;
    private const POSITION_FORCED = 3;
    private const POSITION_ERROR = 4;
    private const POSITION_TILTED = 5;

    // Werte des Verriegelungsstatus (Matter DoorLock_LockState)
    private const LOCK_NOT_FULLY_LOCKED = 0;
    private const LOCK_LOCKED = 1;
    private const LOCK_UNLOCKED = 2;
    private const LOCK_UNLATCHED = 3;

    // Werte der Aktionsvariable (Matter DoorLock_LockUnlockUnbolt); die Griffe bieten nur Öffnen und Verriegeln.
    private const COMMAND_OPEN = 0;
    private const COMMAND_LOCK = 2;
    private const COMMAND_IDENTS = ['Open' => self::COMMAND_OPEN, 'Lock' => self::COMMAND_LOCK];

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
        if ($Ident === 'LockAll') {
            $this->lockAll((int) $Value);
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
     * @return array<int, array{contactVariableId: int, positionVariableId: int, lockStatusVariableId: int, lockActionVariableId: int, label: string, confirmOpen: bool}>|null null bei unlesbarer Liste
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

    // Griff mit Verriegelung: Status- und Aktionsvariable sind beide gesetzt (beide leer = nur Griffstellung).
    private function isLockable(array $handle): bool
    {
        return $handle['lockStatusVariableId'] > 0 || $handle['lockActionVariableId'] > 0;
    }

    private function isValidHandle(array $handle, array $openings): bool
    {
        if (!in_array($handle['contactVariableId'], array_column($openings, 'contactVariableId'), true)
            || !$this->isVariableOfType($handle['positionVariableId'], VARIABLETYPE_INTEGER)) {
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
                $this->SendDebug('Handles', 'Ungültiger Griff mit Griffstellung ' . $handle['positionVariableId'] . ' für Kontakt ' . $handle['contactVariableId'], 0);
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
     * Gültige Griffe je Öffnung (Kontaktvariable), in der Reihenfolge der Liste.
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

    // --------- Befehle ---------

    /**
     * Befehle der Aktionsvariable mit Beschriftung, Icon und Farbe aus ihrer Darstellung; ohne Darstellung eigene Texte.
     *
     * @return array<int, array{caption: string, icon: string, color: string}>
     */
    private function commandOptions(int $actionVariableId): array
    {
        $commands = [
            self::COMMAND_OPEN => ['caption' => $this->Translate('Open (command)'), 'icon' => 'lock-open', 'color' => ''],
            self::COMMAND_LOCK => ['caption' => $this->Translate('Lock'), 'icon' => 'lock', 'color' => '']
        ];
        $seen = [];
        foreach ($this->enumerationOptions($actionVariableId) as $option) {
            $value = (int) $option['value'];
            if (!isset($commands[$value]) || isset($seen[$value])) {
                continue;
            }
            $seen[$value] = true;
            $commands[$value] = [
                'caption' => $option['caption'] !== '' ? $option['caption'] : $commands[$value]['caption'],
                'icon'    => $option['icon'] !== '' ? $option['icon'] : $commands[$value]['icon'],
                'color'   => $option['color']
            ];
        }
        return $commands;
    }

    // Gültiger Griff mit Verriegelung, der zu dieser Instanz gehört
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

    private function sendCommand(int $lockActionVariableId, int $command): bool
    {
        $handle = $this->findLockableHandle($lockActionVariableId);
        if ($handle === null) {
            $this->SendDebug('Command', 'Griff ist nicht verknüpft, ohne Verriegelung oder ungültig: ' . $lockActionVariableId, 0);
            return false;
        }
        // Ein Stellmotor soll einen offenen oder gekippten Flügel nicht verriegeln.
        if ($command === self::COMMAND_LOCK && GetValueInteger($handle['positionVariableId']) !== self::POSITION_CLOSED) {
            $this->SendDebug('Command', 'Verriegeln abgelehnt, Griff ' . $handle['positionVariableId'] . ' ist nicht geschlossen', 0);
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

    // Verriegelt alle geschlossenen, noch nicht verriegelten Griffe einer Öffnung; ein Fehler stoppt die übrigen nicht.
    private function lockAll(int $contactVariableId): void
    {
        $openings = $this->readOpenings() ?? [];
        if (!in_array($contactVariableId, array_column($openings, 'contactVariableId'), true)) {
            $this->SendDebug('LockAll', 'Öffnung ist nicht verknüpft: ' . $contactVariableId, 0);
            return;
        }
        foreach ($this->readHandles() ?? [] as $handle) {
            if ($handle['contactVariableId'] !== $contactVariableId || !$this->isLockable($handle) || !$this->isValidHandle($handle, $openings)) {
                continue;
            }
            if (GetValueInteger($handle['positionVariableId']) === self::POSITION_CLOSED && GetValueInteger($handle['lockStatusVariableId']) !== self::LOCK_LOCKED) {
                $this->sendCommand($handle['lockActionVariableId'], self::COMMAND_LOCK);
            }
        }
    }

    // --------- Zustände ---------

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

    private function positionState(int $position): string
    {
        return match ($position) {
            self::POSITION_OPEN   => 'open',
            self::POSITION_CLOSED => 'closed',
            self::POSITION_TILTED => 'tilted',
            self::POSITION_FORCED => 'forced',
            default               => 'fault'
        };
    }

    private function positionFallbackText(int $position): string
    {
        return match ($position) {
            self::POSITION_OPEN   => $this->Translate('Open'),
            self::POSITION_CLOSED => $this->Translate('Closed'),
            self::POSITION_JAMMED => $this->Translate('Jammed'),
            self::POSITION_FORCED => $this->Translate('Forced open'),
            self::POSITION_ERROR  => $this->Translate('Unknown error'),
            self::POSITION_TILTED => $this->Translate('Tilted'),
            default               => $this->Translate('Unknown')
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

    // Veränderlicher Teil eines Griffs
    private function handleState(array $handle, bool $valid): array
    {
        $state = [
            'id'            => $handle['positionVariableId'],
            'available'     => $valid,
            'position'      => null,
            'positionState' => 'unknown',
            'positionText'  => '',
            'lockable'      => $valid && $this->isLockable($handle),
            'lockStatus'    => null,
            'lockText'      => ''
        ];
        if (!$valid) {
            return $state;
        }
        $position = GetValueInteger($handle['positionVariableId']);
        $state['position'] = $position;
        $state['positionState'] = $this->positionState($position);
        $state['positionText'] = $this->formattedText($handle['positionVariableId'], $position, $this->positionFallbackText($position));
        if ($state['lockable']) {
            $lockStatus = GetValueInteger($handle['lockStatusVariableId']);
            $state['lockStatus'] = $lockStatus;
            $state['lockText'] = $this->formattedText($handle['lockStatusVariableId'], $lockStatus, $this->lockFallbackText($lockStatus));
        }
        return $state;
    }

    /**
     * Gesamtzustand einer Öffnung aus Kontakt und Griffen; es gilt der auffälligste Zustand:
     * gewaltsam geöffnet > offen > gekippt > Störung > geschlossen > verriegelt (alle Griffe mit Motor verriegelt).
     */
    private function openingStatus(bool $contactOpen, array $handleStates): string
    {
        $positions = [];
        $locks = [];
        foreach ($handleStates as $handle) {
            if ($handle['available'] !== true) {
                continue;
            }
            $positions[] = $handle['positionState'];
            if ($handle['lockable']) {
                $locks[] = $handle['lockStatus'];
            }
        }
        if (in_array('forced', $positions, true)) {
            return 'forced';
        }
        if (in_array('open', $positions, true)) {
            return 'open';
        }
        if (in_array('tilted', $positions, true)) {
            return 'tilted';
        }
        if ($contactOpen) {
            return 'open';
        }
        if (in_array('fault', $positions, true) || in_array(self::LOCK_NOT_FULLY_LOCKED, $locks, true)) {
            return 'fault';
        }
        if ($locks !== [] && count(array_filter($locks, static fn ($lock) => $lock === self::LOCK_LOCKED)) === count($locks)) {
            return 'locked';
        }
        return 'closed';
    }

    private function openingText(string $status): string
    {
        return match ($status) {
            'forced' => $this->Translate('Forced open'),
            'open'   => $this->Translate('Open'),
            'tilted' => $this->Translate('Tilted'),
            'fault'  => $this->Translate('Fault'),
            'closed' => $this->Translate('Closed'),
            'locked' => $this->Translate('Locked'),
            default  => $this->Translate('Unknown')
        };
    }

    private function openingIcon(array $opening, string $status): string
    {
        $key = match ($status) {
            'open'   => 'open',
            'tilted' => 'tilted',
            default  => 'closed'
        };
        if (in_array($status, ['forced', 'fault'], true)) {
            return 'triangle-exclamation';
        }
        if ($opening['icons'][$key] !== '') {
            return $opening['icons'][$key];
        }
        // Gekippt ohne eigenes Icon nutzt das eigene Icon für offen.
        if ($key === 'tilted' && $opening['icons']['open'] !== '') {
            return $opening['icons']['open'];
        }
        return self::DEFAULT_ICONS[$opening['kind']][$key];
    }

    // Veränderlicher Teil einer Öffnung mit ihren Griffen
    private function openingState(array $opening, array $handles, array $openings): array
    {
        $valid = $this->isValidOpening($opening);
        $handleStates = [];
        foreach ($handles as $handle) {
            $handleStates[] = $this->handleState($handle, $valid && $this->isValidHandle($handle, $openings));
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
        $contactOpen = GetValueBoolean($opening['contactVariableId']) !== $opening['invert'];
        $status = $this->openingStatus($contactOpen, $handleStates);
        return array_merge($state, [
            'state' => $status,
            'text'  => $this->openingText($status),
            'icon'  => $this->openingIcon($opening, $status)
        ]);
    }

    private function summaryText(array $openingStates): string
    {
        $counts = ['open' => 0, 'tilted' => 0, 'fault' => 0];
        $available = 0;
        foreach ($openingStates as $state) {
            if ($state['available'] !== true) {
                continue;
            }
            $available++;
            $key = match ($state['state']) {
                'open', 'forced' => 'open',
                'tilted'         => 'tilted',
                'fault'          => 'fault',
                default          => ''
            };
            if ($key !== '') {
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
        if ($counts['fault'] > 0) {
            $parts[] = sprintf($this->Translate($counts['fault'] === 1 ? '%d fault' : '%d faults'), $counts['fault']);
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
                $tile['handles'][$handleIndex] += [
                    'label'       => $handle['label'] !== '' ? $handle['label'] : $this->defaultLabel($handle['positionVariableId']),
                    'lockId'      => $handle['lockActionVariableId'],
                    'confirmOpen' => $handle['confirmOpen'],
                    'commands'    => [
                        'open' => $commands[self::COMMAND_OPEN] ?? null,
                        'lock' => $commands[self::COMMAND_LOCK] ?? null
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
