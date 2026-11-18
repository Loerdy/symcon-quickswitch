<?php

declare(strict_types=1);

include_once __DIR__ . '/QuickSwitchTestCase.php';

// Schalten einzelner Aktoren und Alle an/aus
class SwitchTest extends QuickSwitchTestCase
{
    public function testToggleSwitchesAssignedVariable(): void
    {
        $light = $this->createSwitch('Light');
        $module = $this->createQuickSwitch([$this->entry($light)]);

        $module->RequestAction('Toggle', $light);
        $this->assertTrue(GetValueBoolean($light));

        $module->RequestAction('Toggle', $light);
        $this->assertFalse(GetValueBoolean($light));
    }

    public function testToggleIgnoresVariableThatIsNotAssigned(): void
    {
        $light = $this->createSwitch('Light');
        $foreign = $this->createSwitch('Foreign');
        $module = $this->createQuickSwitch([$this->entry($light)]);

        $module->RequestAction('Toggle', $foreign);

        $this->assertFalse(GetValueBoolean($foreign));
    }

    public function testToggleIgnoresVariableWithoutAction(): void
    {
        $sensor = $this->createSwitch('Sensor', false, false);
        $module = $this->createQuickSwitch([$this->entry($sensor)]);

        $module->RequestAction('Toggle', $sensor);

        $this->assertFalse(GetValueBoolean($sensor));
    }

    public function testAllSwitchesOffWhenAnyIncludedIsOn(): void
    {
        $a = $this->createSwitch('A', true);
        $b = $this->createSwitch('B', false);
        $module = $this->createQuickSwitch([$this->entry($a), $this->entry($b)], true);

        $module->RequestAction('All', true);

        $this->assertFalse(GetValueBoolean($a));
        $this->assertFalse(GetValueBoolean($b));
    }

    public function testAllSwitchesOnWhenAllIncludedAreOff(): void
    {
        $a = $this->createSwitch('A');
        $b = $this->createSwitch('B');
        $module = $this->createQuickSwitch([$this->entry($a), $this->entry($b)], true);

        $module->RequestAction('All', true);

        $this->assertTrue(GetValueBoolean($a));
        $this->assertTrue(GetValueBoolean($b));
    }

    public function testAllIgnoresExcludedEntries(): void
    {
        $light = $this->createSwitch('Light');
        $fridge = $this->createSwitch('Fridge', true);
        $module = $this->createQuickSwitch([$this->entry($light), $this->entry($fridge, ['includeInAll' => false])], true);

        // Der ausgeschlossene, eingeschaltete Kühlschrank zählt nicht: Alle an schaltet nur das Licht ein.
        $module->RequestAction('All', true);

        $this->assertTrue(GetValueBoolean($light));
        $this->assertTrue(GetValueBoolean($fridge));

        $module->RequestAction('All', true);

        $this->assertFalse(GetValueBoolean($light));
        $this->assertTrue(GetValueBoolean($fridge));
    }

    public function testAllDoesNothingWhenButtonIsDisabled(): void
    {
        $light = $this->createSwitch('Light');
        $module = $this->createQuickSwitch([$this->entry($light)], false);

        $module->RequestAction('All', true);

        $this->assertFalse(GetValueBoolean($light));
    }

    public function testFailingActuatorDoesNotStopTheOthers(): void
    {
        $a = $this->createSwitch('A');
        $broken = $this->createSwitch('Broken');
        $c = $this->createSwitch('C');
        $GLOBALS['QSW_FAIL'][$broken] = true;
        $module = $this->createQuickSwitch([$this->entry($a), $this->entry($broken), $this->entry($c)], true);

        // Die Skriptausführung der Stubs lässt bei einer Ausnahme ihren Ausgabepuffer offen; hier wieder schließen.
        $level = ob_get_level();
        $module->RequestAction('All', true);
        while (ob_get_level() > $level) {
            ob_end_clean();
        }

        $this->assertTrue(GetValueBoolean($a));
        $this->assertFalse(GetValueBoolean($broken));
        $this->assertTrue(GetValueBoolean($c));
    }

    public function testUnknownActionThrows(): void
    {
        $module = $this->createQuickSwitch([]);

        $this->expectException(Exception::class);
        $module->RequestAction('Unknown', 0);
    }
}
