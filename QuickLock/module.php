<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/QuickSwitchMediaImage.php';

class QuickLock extends IPSModule
{
    use QuickSwitchMediaImage;

    private const MEDIA_MESSAGES = [MM_UPDATE, MM_CHANGEFILE, MM_DELETE];
    // Eigene Bilder je Verriegelungsstatus: Schlüssel in der Kachel => Spalte der Eintragsliste
    private const IMAGE_COLUMNS = ['locked' => 'imageLocked', 'unlocked' => 'imageUnlocked', 'unlatched' => 'imageUnlatched'];

    // Werte der Aktionsvariable (Matter DoorLock_LockUnlockUnbolt)
    private const COMMAND_OPEN = 0;
    private const COMMAND_UNLOCK = 1;
    private const COMMAND_LOCK = 2;
    private const COMMAND_IDENTS = ['Open' => self::COMMAND_OPEN, 'Unlock' => self::COMMAND_UNLOCK, 'Lock' => self::COMMAND_LOCK];

    // Werte der Statusvariable (Matter DoorLock_LockState)
    private const STATE_NOT_FULLY_LOCKED = 0;
    private const STATE_LOCKED = 1;
    private const STATE_UNLOCKED = 2;
    private const STATE_UNLATCHED = 3;

    private const STATUS_INVALID_ENTRY = 201;
    private const STATUS_INVALID_LIST = 202;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('Entries', '[]');
        $this->RegisterPropertyBoolean('ShowStatus', true);

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

        $entries = $this->readEntries();
        $this->registerObjectMessages($entries ?? []);
        $this->SetStatus($this->configurationStatus($entries));
        $this->updateVisualization();
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data): void
    {
        switch ($Message) {
            case IPS_KERNELSTARTED:
                $this->ApplyChanges();
                break;

            case VM_UPDATE:
                // Nur Zustände übertragen; Bilder bleiben in der Kachel unverändert.
                $this->updateStates();
                break;

            case VM_DELETE:
                $this->SetStatus($this->configurationStatus($this->readEntries()));
                $this->updateVisualization();
                break;

            default:
                if (in_array($Message, self::MEDIA_MESSAGES, true)) {
                    $this->updateVisualization();
                }
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
            $initialState = '{"type":"full","showStatus":false,"entries":[]}';
        }

        return str_replace('__QUICKLOCK_INITIAL_STATE__', $initialState, $template);
    }

    public function RequestAction($Ident, $Value): void
    {
        if ($Ident === 'Toggle') {
            $this->toggleLock((int) $Value);
            return;
        }
        if (array_key_exists($Ident, self::COMMAND_IDENTS)) {
            $this->sendCommand((int) $Value, self::COMMAND_IDENTS[$Ident]);
            return;
        }
        throw new Exception(sprintf($this->Translate('Unknown action: %s'), $Ident));
    }

    // --------- Einträge ---------

    /**
     * @return array<int, array{statusVariableId: int, actionVariableId: int, label: string, showLabel: bool, confirmOpen: bool, confirmUnlock: bool, images: array<string, int>}>|null null bei unlesbarer Liste
     */
    private function readEntries(): ?array
    {
        $rows = json_decode($this->ReadPropertyString('Entries'), true);
        if (!is_array($rows)) {
            $this->SendDebug('Entries', 'Liste der Schlösser ist kein gültiges JSON', 0);
            return null;
        }

        $entries = [];
        foreach (array_values($rows) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $images = [];
            foreach (self::IMAGE_COLUMNS as $key => $column) {
                $images[$key] = is_int($row[$column] ?? null) ? $row[$column] : 0;
            }
            $entries[] = [
                'images'           => $images,
                'statusVariableId' => is_int($row['statusVariable'] ?? null) ? $row['statusVariable'] : 0,
                'actionVariableId' => is_int($row['actionVariable'] ?? null) ? $row['actionVariable'] : 0,
                'label'            => is_string($row['label'] ?? null) ? trim($row['label']) : '',
                'showLabel'        => ($row['showLabel'] ?? true) !== false,
                'confirmOpen'      => ($row['confirmOpen'] ?? true) !== false,
                'confirmUnlock'    => ($row['confirmUnlock'] ?? false) === true
            ];
        }
        return $entries;
    }

    private function isIntegerVariable(int $variableId): bool
    {
        return $variableId > 0
            && IPS_VariableExists($variableId)
            && IPS_GetVariable($variableId)['VariableType'] === VARIABLETYPE_INTEGER;
    }

    private function isValidEntry(array $entry): bool
    {
        return $this->isIntegerVariable($entry['statusVariableId'])
            && $this->isIntegerVariable($entry['actionVariableId'])
            && HasAction($entry['actionVariableId']);
    }

    private function configurationStatus(?array $entries): int
    {
        if ($entries === null) {
            return self::STATUS_INVALID_LIST;
        }
        if ($entries === []) {
            return IS_INACTIVE;
        }
        foreach ($entries as $entry) {
            if (!$this->isValidEntry($entry)) {
                $this->SendDebug('Entries', 'Ungültiger Eintrag mit Status ' . $entry['statusVariableId'] . ' / Aktion ' . $entry['actionVariableId'], 0);
                return self::STATUS_INVALID_ENTRY;
            }
        }
        return IS_ACTIVE;
    }

    // Meldet nur die Nachrichten der aktuell verknüpften Variablen und Medien an und alle übrigen ab.
    private function registerObjectMessages(array $entries): void
    {
        $wanted = [];
        foreach ($entries as $entry) {
            if ($entry['statusVariableId'] > 0 && IPS_VariableExists($entry['statusVariableId'])) {
                $wanted[$entry['statusVariableId']][VM_UPDATE] = true;
                $wanted[$entry['statusVariableId']][VM_DELETE] = true;
            }
            if ($entry['actionVariableId'] > 0 && IPS_VariableExists($entry['actionVariableId'])) {
                $wanted[$entry['actionVariableId']][VM_DELETE] = true;
            }
            foreach ($entry['images'] as $mediaId) {
                if ($mediaId > 0 && IPS_MediaExists($mediaId)) {
                    foreach (self::MEDIA_MESSAGES as $message) {
                        $wanted[$mediaId][$message] = true;
                    }
                }
            }
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

    private function findEntry(int $actionVariableId): ?array
    {
        foreach ($this->readEntries() ?? [] as $entry) {
            if ($entry['actionVariableId'] === $actionVariableId) {
                return $entry;
            }
        }
        return null;
    }

    // --------- Befehle ---------

    /**
     * Befehle, die die Aktionsvariable laut ihrer Darstellung anbietet. Ohne lesbare Darstellung gelten Öffnen und
     * Verriegeln (Schloss mit Klinke); Öffnen ohne Entriegeln wird nie nur vermutet.
     *
     * @return array<int, array{caption: string, icon: string, color: string}> Befehlswert => Darstellung
     */
    private function commandOptions(int $actionVariableId): array
    {
        $defaults = [
            self::COMMAND_OPEN   => ['caption' => $this->Translate('Open'), 'icon' => 'door-open', 'color' => ''],
            self::COMMAND_UNLOCK => ['caption' => $this->Translate('Unlock'), 'icon' => 'lock-open', 'color' => ''],
            self::COMMAND_LOCK   => ['caption' => $this->Translate('Lock'), 'icon' => 'lock', 'color' => '']
        ];

        $commands = [];
        foreach ($this->presentationOptions($actionVariableId) as $value => $option) {
            if (!isset($defaults[$value])) {
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

    /**
     * Liest die Auswahlwerte aus der Darstellung (Aufzählung, auch über eine Vorlage) oder dem Variablenprofil.
     *
     * @return array<int, array{caption: string, icon: string, color: string}>
     */
    private function presentationOptions(int $variableId): array
    {
        if (!IPS_VariableExists($variableId)) {
            return [];
        }
        try {
            $presentation = IPS_GetVariablePresentation($variableId);
        } catch (Throwable $e) {
            $this->SendDebug('Presentation', 'Darstellung von ' . $variableId . ' nicht lesbar: ' . $e->getMessage(), 0);
            return [];
        }

        if (($presentation['PRESENTATION'] ?? '') === VARIABLE_PRESENTATION_LEGACY) {
            return $this->profileOptions(is_string($presentation['PROFILE'] ?? null) ? $presentation['PROFILE'] : '');
        }

        $raw = $presentation['OPTIONS'] ?? null;
        if ($raw === null && is_string($presentation['TEMPLATE'] ?? null) && IPS_TemplateExists($presentation['TEMPLATE'])) {
            $values = IPS_GetTemplate($presentation['TEMPLATE'])['Values'] ?? [];
            $raw = is_array($values) ? ($values['OPTIONS'] ?? null) : null;
        }
        $rows = is_string($raw) ? json_decode($raw, true) : $raw;
        if (!is_array($rows)) {
            return [];
        }

        $options = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !is_numeric($row['Value'] ?? null)) {
                continue;
            }
            $options[(int) $row['Value']] = [
                'caption' => is_string($row['Caption'] ?? null) ? $row['Caption'] : '',
                'icon'    => ($row['IconActive'] ?? false) === true && is_string($row['IconValue'] ?? null) ? $row['IconValue'] : '',
                'color'   => $this->hexColor($row['Color'] ?? -1)
            ];
        }
        return $options;
    }

    private function profileOptions(string $profileName): array
    {
        if ($profileName === '' || !IPS_VariableProfileExists($profileName)) {
            return [];
        }
        $options = [];
        foreach (IPS_GetVariableProfile($profileName)['Associations'] ?? [] as $association) {
            if (!is_array($association) || !is_numeric($association['Value'] ?? null)) {
                continue;
            }
            $options[(int) $association['Value']] = [
                'caption' => is_string($association['Name'] ?? null) ? $association['Name'] : '',
                'icon'    => is_string($association['Icon'] ?? null) ? $association['Icon'] : '',
                'color'   => $this->hexColor($association['Color'] ?? -1)
            ];
        }
        return $options;
    }

    private function hexColor(mixed $color): string
    {
        return is_int($color) && $color >= 0 && $color <= 0xFFFFFF ? sprintf('#%06X', $color) : '';
    }

    // Antippen: verriegelt => aufschließen (Entriegeln, sonst Öffnen), sonst verriegeln.
    private function toggleCommand(int $status, array $commands): int
    {
        if ($status !== self::STATE_LOCKED) {
            return self::COMMAND_LOCK;
        }
        return isset($commands[self::COMMAND_UNLOCK]) ? self::COMMAND_UNLOCK : self::COMMAND_OPEN;
    }

    private function toggleLock(int $actionVariableId): void
    {
        $entry = $this->findEntry($actionVariableId);
        if ($entry === null || !$this->isValidEntry($entry)) {
            $this->SendDebug('Toggle', 'Schloss ist nicht verknüpft oder ungültig: ' . $actionVariableId, 0);
            return;
        }
        $command = $this->toggleCommand(GetValueInteger($entry['statusVariableId']), $this->commandOptions($actionVariableId));
        $this->sendCommand($actionVariableId, $command);
    }

    private function sendCommand(int $actionVariableId, int $command): void
    {
        // Nur Schlösser bedienen, die dieser Instanz zugewiesen sind, und nur mit Befehlen, die sie anbieten.
        $entry = $this->findEntry($actionVariableId);
        if ($entry === null || !$this->isValidEntry($entry)) {
            $this->SendDebug('Command', 'Schloss ist nicht verknüpft oder ungültig: ' . $actionVariableId, 0);
            return;
        }
        if (!isset($this->commandOptions($actionVariableId)[$command])) {
            $this->SendDebug('Command', 'Befehl ' . $command . ' wird von ' . $actionVariableId . ' nicht angeboten', 0);
            return;
        }

        try {
            $success = RequestAction($actionVariableId, $command);
        } catch (Throwable $e) {
            $this->SendDebug('Command', 'Ausnahme bei Variable ' . $actionVariableId . ': ' . $e->getMessage(), 0);
            $success = false;
        }
        if (!$success) {
            $this->LogMessage(sprintf($this->Translate('Command %d for variable %d failed.'), $command, $actionVariableId), KL_WARNING);
            $this->sendToTile(['type' => 'failed', 'id' => $actionVariableId]);
            return;
        }
        $this->SendDebug('Command', 'Befehl ' . $command . ' an Variable ' . $actionVariableId . ' gesendet', 0);
    }

    // --------- Kachel ---------

    private function defaultLabel(int $actionVariableId): string
    {
        if (!IPS_VariableExists($actionVariableId)) {
            return '';
        }
        // Die Matter-Variablen heißen nur "Türschloss"; der Gerätename ist aussagekräftiger.
        $parentId = IPS_GetParent($actionVariableId);
        return $parentId > 0 && IPS_InstanceExists($parentId) ? IPS_GetName($parentId) : IPS_GetName($actionVariableId);
    }

    private function statusText(int $statusVariableId, int $status): string
    {
        // Text aus der Darstellung der Statusvariable; ohne Darstellung liefert Symcon nur die Zahl.
        try {
            $formatted = trim((string) GetValueFormatted($statusVariableId));
        } catch (Throwable $e) {
            $formatted = '';
        }
        if ($formatted !== '' && $formatted !== '-' && $formatted !== (string) $status) {
            return $formatted;
        }
        return match ($status) {
            self::STATE_NOT_FULLY_LOCKED => $this->Translate('Not fully locked'),
            self::STATE_LOCKED           => $this->Translate('Locked'),
            self::STATE_UNLOCKED         => $this->Translate('Unlocked'),
            self::STATE_UNLATCHED        => $this->Translate('Unlatched'),
            default                      => $this->Translate('Unknown')
        };
    }

    /**
     * Grafik zum Verriegelungsstatus: Icon und Farbe des passenden Befehls aus der Darstellung der Aktionsvariable.
     *
     * @return array{state: string, icon: string, color: string}
     */
    private function statusGraphic(int $status, array $commands): array
    {
        $unlockCommand = $commands[self::COMMAND_UNLOCK] ?? $commands[self::COMMAND_OPEN];
        return match ($status) {
            self::STATE_LOCKED           => ['state' => 'locked', 'icon' => $commands[self::COMMAND_LOCK]['icon'], 'color' => $commands[self::COMMAND_LOCK]['color']],
            self::STATE_UNLOCKED         => ['state' => 'unlocked', 'icon' => $unlockCommand['icon'], 'color' => $unlockCommand['color']],
            self::STATE_UNLATCHED        => ['state' => 'unlatched', 'icon' => ($commands[self::COMMAND_OPEN] ?? $unlockCommand)['icon'], 'color' => ($commands[self::COMMAND_OPEN] ?? $unlockCommand)['color']],
            self::STATE_NOT_FULLY_LOCKED => ['state' => 'notLocked', 'icon' => 'triangle-exclamation', 'color' => ''],
            default                      => ['state' => 'unknown', 'icon' => 'question', 'color' => '']
        };
    }

    // Veränderlicher Teil eines Schlosses: Verfügbarkeit, Status und passende Grafik.
    private function entryState(array $entry, array $commands): array
    {
        $state = [
            'id'        => $entry['actionVariableId'],
            'available' => $this->isValidEntry($entry),
            'status'    => null,
            'state'     => 'unknown',
            'text'      => '',
            'icon'      => 'lock',
            'color'     => ''
        ];
        if (!$state['available']) {
            return $state;
        }
        $status = GetValueInteger($entry['statusVariableId']);
        return array_merge($state, $this->statusGraphic($status, $commands), [
            'status' => $status,
            'text'   => $this->statusText($entry['statusVariableId'], $status)
        ]);
    }

    private function buildFullState(): array
    {
        $tiles = [];
        foreach ($this->readEntries() ?? [] as $entry) {
            $commands = $this->commandOptions($entry['actionVariableId']);
            $tile = [
                'label'         => $entry['label'] !== '' ? $entry['label'] : $this->defaultLabel($entry['actionVariableId']),
                'showLabel'     => $entry['showLabel'],
                'confirmOpen'   => $entry['confirmOpen'],
                'confirmUnlock' => $entry['confirmUnlock'],
                'commands'      => [],
                'images'        => []
            ];
            foreach ($commands as $value => $command) {
                $tile['commands'][] = ['value' => $value] + $command;
            }
            foreach ($entry['images'] as $key => $mediaId) {
                $tile['images'][$key] = $this->readImageDataUrl($mediaId);
            }
            $tiles[] = $this->entryState($entry, $commands) + $tile;
        }

        return [
            'type'       => 'full',
            'showStatus' => $this->ReadPropertyBoolean('ShowStatus'),
            'entries'    => $tiles
        ];
    }

    private function buildStates(): array
    {
        $states = [];
        foreach ($this->readEntries() ?? [] as $entry) {
            $states[] = $this->entryState($entry, $this->commandOptions($entry['actionVariableId']));
        }
        return ['type' => 'states', 'states' => $states];
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