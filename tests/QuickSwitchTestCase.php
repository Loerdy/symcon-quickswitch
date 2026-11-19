<?php

declare(strict_types=1);

include_once __DIR__ . '/stubs/autoload.php';

use PHPUnit\Framework\TestCase;

// Gemeinsame Hilfen: Library laden, schaltbare Variablen, Bilder und Instanzen anlegen, private Methoden aufrufen.
abstract class QuickSwitchTestCase extends TestCase
{
    protected const MODULE = '{D3685124-6D26-278F-31C4-DFDA6D580F21}';
    protected const PNG = "\x89PNG\r\n\x1A\n" . 'png-test-data';
    protected const JPEG = "\xFF\xD8\xFF\xE0" . 'jpeg-test-data';

    private int $actionScript = 0;

    protected function setUp(): void
    {
        parent::setUp();
        IPS\Kernel::reset();
        IPS\ModuleLoader::loadLibrary(__DIR__ . '/../library.json');
        $GLOBALS['QSW_FAIL'] = [];

        // Aktionsskript wie eine Geräteaktion: setzt den gewünschten Wert, sofern für die Variable kein Fehler simuliert wird.
        $this->actionScript = IPS_CreateScript(0);
        IPS_SetScriptContent($this->actionScript, '<?php
            if (!empty($GLOBALS["QSW_FAIL"][$_IPS["VARIABLE"]])) {
                throw new Exception("Simulierter Gerätefehler");
            }
            SetValue($_IPS["VARIABLE"], $_IPS["VALUE"]);');
    }

    protected function createSwitch(string $name, bool $value = false, bool $withAction = true, int $parentID = 0): int
    {
        $id = IPS_CreateVariable(VARIABLETYPE_BOOLEAN);
        IPS_SetName($id, $name);
        SetValue($id, $value);
        if ($withAction) {
            IPS_SetVariableCustomAction($id, $this->actionScript);
        }
        if ($parentID > 0) {
            IPS_SetParent($id, $parentID);
        }
        return $id;
    }

    protected function createImage(string $content): int
    {
        $id = IPS_CreateMedia(MEDIATYPE_IMAGE);
        IPS_SetMediaFile($id, 'media/test_' . $id . '.png', false);
        IPS_SetMediaContent($id, base64_encode($content));
        return $id;
    }

    protected function entry(int $variableID, array $overrides = []): array
    {
        return array_merge([
            'variable'     => $variableID,
            'label'        => '',
            'showLabel'    => true,
            'icon'         => 'lightbulb',
            'color'        => -1,
            'imageOn'      => 0,
            'imageOff'     => 0,
            'includeInAll' => true
        ], $overrides);
    }

    // Zusatzvariable mit Aktion; $presentation wird als benutzerdefinierte Darstellung gesetzt.
    protected function createExtra(int $type, mixed $value, ?array $presentation = null, bool $withAction = true): int
    {
        $id = IPS_CreateVariable($type);
        IPS_SetName($id, 'Extra ' . $id);
        SetValue($id, $value);
        if ($presentation !== null) {
            IPS_SetVariableCustomPresentation($id, $presentation);
        }
        if ($withAction) {
            IPS_SetVariableCustomAction($id, $this->actionScript);
        }
        return $id;
    }

    protected function createQuickSwitch(array $entries, bool $showAll = false, ?string $rawEntries = null, array|string $extras = []): QuickSwitch
    {
        $id = IPS_CreateInstance(self::MODULE);
        IPS_SetProperty($id, 'Extras', is_string($extras) ? $extras : json_encode($extras));
        IPS_SetProperty($id, 'Entries', $rawEntries ?? json_encode($entries));
        IPS_SetProperty($id, 'ShowAllButton', $showAll);
        IPS_ApplyChanges($id);
        return IPS\InstanceManager::getInstanceInterface($id);
    }

    protected function instanceStatus(QuickSwitch $module): int
    {
        return IPS_GetInstance($this->instanceID($module))['InstanceStatus'];
    }

    protected function instanceID(QuickSwitch $module): int
    {
        return (new ReflectionProperty($module, 'InstanceID'))->getValue($module);
    }

    protected function fullState(QuickSwitch $module): array
    {
        return $this->call($module, 'buildFullState');
    }

    protected function call(object $object, string $method, mixed ...$arguments): mixed
    {
        return (new ReflectionMethod($object, $method))->invoke($object, ...$arguments);
    }
}
