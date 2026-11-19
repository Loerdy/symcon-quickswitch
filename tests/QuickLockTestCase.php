<?php

declare(strict_types=1);

include_once __DIR__ . '/stubs/autoload.php';

use PHPUnit\Framework\TestCase;

// Gemeinsame Hilfen: Library laden, Schlösser wie über Matter (Status- und Aktionsvariable) anlegen, private Methoden aufrufen.
abstract class QuickLockTestCase extends TestCase
{
    protected const MODULE = '{1C6242D2-94AD-03EB-F761-EED556D56353}';

    // Darstellungen der Aktionsvariablen wie bei den Nuki-Schlössern
    protected const HANDLE_OPTIONS = [
        ['Value' => 0, 'Caption' => 'Öffnen', 'IconActive' => true, 'IconValue' => 'unlock', 'Color' => 0xFF0000],
        ['Value' => 2, 'Caption' => 'Verriegeln', 'IconActive' => true, 'IconValue' => 'lock', 'Color' => 0x00FF00]
    ];
    protected const KNOB_OPTIONS = [
        ['Value' => 0, 'Caption' => 'Öffnen', 'IconActive' => true, 'IconValue' => 'unlock', 'Color' => 0xFFFF00],
        ['Value' => 1, 'Caption' => 'Entriegeln', 'IconActive' => true, 'IconValue' => 'unlock', 'Color' => 0xFF0000],
        ['Value' => 2, 'Caption' => 'Verriegeln', 'IconActive' => true, 'IconValue' => 'lock', 'Color' => 0x00FF00]
    ];

    private int $actionScript = 0;

    protected function setUp(): void
    {
        parent::setUp();
        IPS\Kernel::reset();
        IPS\ModuleLoader::loadLibrary(__DIR__ . '/../library.json');
        $GLOBALS['QLK_FAIL'] = [];
        $GLOBALS['QLK_COMMANDS'] = [];

        // Aktionsskript wie die Geräteaktion: merkt sich den Befehl, sofern für die Variable kein Fehler simuliert wird.
        $this->actionScript = IPS_CreateScript(0);
        IPS_SetScriptContent($this->actionScript, '<?php
            if (!empty($GLOBALS["QLK_FAIL"][$_IPS["VARIABLE"]])) {
                throw new Exception("Simulierter Gerätefehler");
            }
            $GLOBALS["QLK_COMMANDS"][] = [$_IPS["VARIABLE"], $_IPS["VALUE"]];
            SetValue($_IPS["VARIABLE"], $_IPS["VALUE"]);');
    }

    protected function createStatus(int $value, int $parentID = 0): int
    {
        $id = IPS_CreateVariable(VARIABLETYPE_INTEGER);
        IPS_SetName($id, 'Verriegelungsstatus');
        SetValue($id, $value);
        if ($parentID > 0) {
            IPS_SetParent($id, $parentID);
        }
        return $id;
    }

    protected function createAction(?array $options, bool $withAction = true, int $parentID = 0): int
    {
        $id = IPS_CreateVariable(VARIABLETYPE_INTEGER);
        IPS_SetName($id, 'Türschloss');
        SetValue($id, 1);
        if ($options !== null) {
            IPS_SetVariableCustomPresentation($id, ['PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION, 'OPTIONS' => json_encode($options)]);
        }
        if ($withAction) {
            IPS_SetVariableCustomAction($id, $this->actionScript);
        }
        if ($parentID > 0) {
            IPS_SetParent($id, $parentID);
        }
        return $id;
    }

    protected function entry(int $statusID, int $actionID, array $overrides = []): array
    {
        return array_merge([
            'statusVariable' => $statusID,
            'actionVariable' => $actionID,
            'label'          => '',
            'showLabel'      => true,
            'confirmOpen'    => true,
            'confirmUnlock'  => false
        ], $overrides);
    }

    protected function createQuickLock(array $entries, ?string $rawEntries = null): QuickLock
    {
        $id = IPS_CreateInstance(self::MODULE);
        IPS_SetProperty($id, 'Entries', $rawEntries ?? json_encode($entries));
        IPS_ApplyChanges($id);
        return IPS\InstanceManager::getInstanceInterface($id);
    }

    protected function instanceStatus(QuickLock $module): int
    {
        return IPS_GetInstance($this->instanceID($module))['InstanceStatus'];
    }

    protected function instanceID(QuickLock $module): int
    {
        return (new ReflectionProperty($module, 'InstanceID'))->getValue($module);
    }

    protected function state(QuickLock $module): array
    {
        return $this->call($module, 'buildState');
    }

    protected function commandValues(array $tile): array
    {
        return array_column($tile['commands'], 'value');
    }

    protected function call(object $object, string $method, mixed ...$arguments): mixed
    {
        return (new ReflectionMethod($object, $method))->invoke($object, ...$arguments);
    }
}