<?php

declare(strict_types=1);

class QuickSwitch extends IPSModule
{
    // Bilder werden als Data-URL an die Kachel übertragen; größere Bilder würden jede Vollaktualisierung ausbremsen.
    private const IMAGE_MAX_BYTES = 524288;
    private const VARIABLE_MESSAGES = [VM_UPDATE, VM_DELETE];
    private const MEDIA_MESSAGES = [MM_UPDATE, MM_CHANGEFILE, MM_DELETE];
    private const DEFAULT_ICON = 'power-off';
    private const STATUS_INVALID_ENTRY = 201;
    private const STATUS_INVALID_LIST = 202;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('Entries', '[]');
        $this->RegisterPropertyBoolean('ShowAllButton', false);
        $this->RegisterPropertyString('AllLabel', '');
        $this->RegisterPropertyString('AllIcon', self::DEFAULT_ICON);

        $this->RegisterMessage(0, IPS_KERNELSTARTED);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $this->SetVisualizationType(1);

        // Verknüpfte Variablen und Medien erst prüfen, wenn der Kernel bereit ist; sonst übernimmt das IPS_KERNELSTARTED.
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
                // Nur Schaltzustände übertragen; Bilder bleiben in der Kachel unverändert.
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
            $initialState = '{"type":"full","all":{"show":false},"entries":[]}';
        }

        return str_replace('__QUICKSWITCH_INITIAL_STATE__', $initialState, $template);
    }

    public function RequestAction($Ident, $Value): void
    {
        switch ($Ident) {
            case 'Toggle':
                $this->toggleEntry((int) $Value);
                break;

            case 'All':
                $this->switchAll();
                break;

            default:
                throw new Exception(sprintf($this->Translate('Unknown action: %s'), $Ident));
        }
    }

    // --------- Einträge ---------

    /**
     * @return array<int, array{variableId: int, label: string, showLabel: bool, icon: string, color: int, imageOn: int, imageOff: int, includeInAll: bool}>|null null bei unlesbarer Liste
     */
    private function readEntries(): ?array
    {
        $rows = json_decode($this->ReadPropertyString('Entries'), true);
        if (!is_array($rows)) {
            $this->SendDebug('Entries', 'Liste der Aktoren ist kein gültiges JSON', 0);
            return null;
        }

        $entries = [];
        foreach (array_values($rows) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $entries[] = [
                'variableId'   => is_int($row['variable'] ?? null) ? $row['variable'] : 0,
                'label'        => is_string($row['label'] ?? null) ? trim($row['label']) : '',
                'showLabel'    => ($row['showLabel'] ?? true) !== false,
                'icon'         => is_string($row['icon'] ?? null) ? $row['icon'] : '',
                'color'        => is_int($row['color'] ?? null) ? $row['color'] : -1,
                'imageOn'      => is_int($row['imageOn'] ?? null) ? $row['imageOn'] : 0,
                'imageOff'     => is_int($row['imageOff'] ?? null) ? $row['imageOff'] : 0,
                'includeInAll' => ($row['includeInAll'] ?? true) !== false
            ];
        }
        return $entries;
    }

    private function isSwitchable(int $variableId): bool
    {
        return $variableId > 0
            && IPS_VariableExists($variableId)
            && IPS_GetVariable($variableId)['VariableType'] === VARIABLETYPE_BOOLEAN
            && HasAction($variableId);
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
            if (!$this->isSwitchable($entry['variableId'])) {
                $this->SendDebug('Entries', 'Ungültiger Eintrag mit Variable ' . $entry['variableId'], 0);
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
            if ($entry['variableId'] > 0 && IPS_VariableExists($entry['variableId'])) {
                foreach (self::VARIABLE_MESSAGES as $message) {
                    $wanted[$entry['variableId']][$message] = true;
                }
            }
            foreach ([$entry['imageOn'], $entry['imageOff']] as $mediaId) {
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

    // --------- Schalten ---------

    private function toggleEntry(int $variableId): void
    {
        // Nur Variablen schalten, die dieser Instanz zugewiesen sind.
        $known = false;
        foreach ($this->readEntries() ?? [] as $entry) {
            if ($entry['variableId'] === $variableId) {
                $known = true;
                break;
            }
        }
        if (!$known) {
            $this->SendDebug('Toggle', 'Variable ist nicht verknüpft: ' . $variableId, 0);
            return;
        }
        if (!$this->isSwitchable($variableId)) {
            $this->SendDebug('Toggle', 'Variable ist nicht schaltbar: ' . $variableId, 0);
            return;
        }
        $this->switchVariable($variableId, !GetValueBoolean($variableId));
    }

    private function switchAll(): void
    {
        if (!$this->ReadPropertyBoolean('ShowAllButton')) {
            $this->SendDebug('All', 'Alle an/aus ist nicht aktiviert', 0);
            return;
        }

        $variableIds = [];
        foreach ($this->readEntries() ?? [] as $entry) {
            if ($entry['includeInAll'] && $this->isSwitchable($entry['variableId'])) {
                $variableIds[$entry['variableId']] = true;
            }
        }
        $variableIds = array_keys($variableIds);

        $anyOn = false;
        foreach ($variableIds as $variableId) {
            if (GetValueBoolean($variableId)) {
                $anyOn = true;
                break;
            }
        }
        // Ist mindestens ein Aktor an, wird alles ausgeschaltet, sonst alles eingeschaltet.
        $target = !$anyOn;
        $this->SendDebug('All', 'Schalte ' . count($variableIds) . ' Aktor(en) ' . ($target ? 'an' : 'aus'), 0);
        foreach ($variableIds as $variableId) {
            if (GetValueBoolean($variableId) !== $target) {
                // Ein Fehler bei einem Aktor verhindert nicht das Schalten der übrigen.
                $this->switchVariable($variableId, $target);
            }
        }
    }

    private function switchVariable(int $variableId, bool $value): void
    {
        try {
            $success = RequestAction($variableId, $value);
        } catch (Throwable $e) {
            $this->SendDebug('Switch', 'Ausnahme bei Variable ' . $variableId . ': ' . $e->getMessage(), 0);
            $success = false;
        }
        if (!$success) {
            $this->LogMessage(sprintf($this->Translate('Switching variable %d failed.'), $variableId), KL_WARNING);
            return;
        }
        $this->SendDebug('Switch', 'Variable ' . $variableId . ' auf ' . ($value ? 'an' : 'aus') . ' geschaltet', 0);
    }

    // --------- Bilder ---------

    private function readImageDataUrl(int $mediaId): string
    {
        if ($mediaId <= 0) {
            return '';
        }
        if (!IPS_MediaExists($mediaId)) {
            $this->SendDebug('Images', 'Medium ' . $mediaId . ' existiert nicht', 0);
            return '';
        }
        $media = IPS_GetMedia($mediaId);
        if (($media['MediaType'] ?? -1) !== MEDIATYPE_IMAGE || (int) ($media['MediaSize'] ?? 0) > self::IMAGE_MAX_BYTES) {
            $this->SendDebug('Images', 'Medium ' . $mediaId . ' ist kein Bild oder größer als 512 KB', 0);
            return '';
        }
        $content = IPS_GetMediaContent($mediaId);
        $signature = $content !== '' ? base64_decode(substr($content, 0, 16), true) : false;
        if (is_string($signature)) {
            if (substr($signature, 0, 3) === "\xFF\xD8\xFF") {
                return 'data:image/jpeg;base64,' . $content;
            }
            if (substr($signature, 0, 8) === "\x89PNG\r\n\x1A\n") {
                return 'data:image/png;base64,' . $content;
            }
        }
        $this->SendDebug('Images', 'Medium ' . $mediaId . ' ist kein JPEG oder PNG', 0);
        return '';
    }

    // --------- Kachel ---------

    private function defaultLabel(int $variableId): string
    {
        if (!IPS_VariableExists($variableId)) {
            return '';
        }
        // Statusvariablen heißen oft nur "Status"; der Gerätename ist dann aussagekräftiger.
        $parentId = IPS_GetParent($variableId);
        return $parentId > 0 && IPS_InstanceExists($parentId) ? IPS_GetName($parentId) : IPS_GetName($variableId);
    }

    private function buildFullState(): array
    {
        $tiles = [];
        foreach ($this->readEntries() ?? [] as $entry) {
            $available = $this->isSwitchable($entry['variableId']);
            $color = $entry['color'] >= 0 && $entry['color'] <= 0xFFFFFF ? sprintf('#%06X', $entry['color']) : '';
            $tiles[] = [
                'id'           => $entry['variableId'],
                'label'        => $entry['label'] !== '' ? $entry['label'] : $this->defaultLabel($entry['variableId']),
                'showLabel'    => $entry['showLabel'],
                'icon'         => $entry['icon'],
                'color'        => $color,
                'imageOn'      => $this->readImageDataUrl($entry['imageOn']),
                'imageOff'     => $this->readImageDataUrl($entry['imageOff']),
                'includeInAll' => $entry['includeInAll'],
                'available'    => $available,
                'on'           => $available && GetValueBoolean($entry['variableId'])
            ];
        }

        return [
            'type'    => 'full',
            'all'     => [
                'show'  => $this->ReadPropertyBoolean('ShowAllButton'),
                'label' => $this->ReadPropertyString('AllLabel'),
                'icon'  => $this->ReadPropertyString('AllIcon')
            ],
            'entries' => $tiles
        ];
    }

    private function buildStates(): array
    {
        $states = [];
        foreach ($this->readEntries() ?? [] as $entry) {
            $available = $this->isSwitchable($entry['variableId']);
            $states[] = [
                'id'        => $entry['variableId'],
                'available' => $available,
                'on'        => $available && GetValueBoolean($entry['variableId'])
            ];
        }
        return ['type' => 'states', 'states' => $states];
    }

    private function updateVisualization(): void
    {
        try {
            $this->UpdateVisualizationValue(json_encode($this->buildFullState(), JSON_THROW_ON_ERROR));
        } catch (Throwable $e) {
            $this->SendDebug('Visualization', 'Kachelzustand konnte nicht übertragen werden: ' . $e->getMessage(), 0);
        }
    }

    private function updateStates(): void
    {
        try {
            $this->UpdateVisualizationValue(json_encode($this->buildStates(), JSON_THROW_ON_ERROR));
        } catch (Throwable $e) {
            $this->SendDebug('Visualization', 'Schaltzustände konnten nicht übertragen werden: ' . $e->getMessage(), 0);
        }
    }
}
