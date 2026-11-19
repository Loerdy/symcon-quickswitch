<?php

declare(strict_types=1);

include_once __DIR__ . '/QuickSwitchTestCase.php';

// Zusatzvariablen im Pfeil-Menü: Bedienelemente, Werte setzen, Zustände und Konfiguration
class ExtrasTest extends QuickSwitchTestCase
{
    // Staubsauger-Befehle wie in der Aufzählung des Saugroboters
    private const VACUUM_OPTIONS = [
        ['Value' => 0, 'Caption' => 'Start', 'IconActive' => false, 'IconValue' => '', 'Color' => -1],
        ['Value' => 1, 'Caption' => 'Stop', 'IconActive' => false, 'IconValue' => '', 'Color' => -1],
        ['Value' => 2, 'Caption' => 'Pause', 'IconActive' => true, 'IconValue' => 'pause', 'Color' => 0x00AA88]
    ];

    // --------- Bedienelemente ---------

    public function testControlsFollowVariableTypeAndPresentation(): void
    {
        $lamp = $this->createSwitch('Lamp');
        $vacuum = $this->createExtra(VARIABLETYPE_INTEGER, 1, $this->enumeration(self::VACUUM_OPTIONS));
        $brightness = $this->createExtra(VARIABLETYPE_INTEGER, 40, $this->slider(0, 100, 5, 0, ' %'));
        $channel = $this->createExtra(VARIABLETYPE_BOOLEAN, true);
        $module = $this->createQuickSwitch([$this->entry($lamp)], false, null, [
            $this->extraRow($lamp, $vacuum, 'Saugen'),
            $this->extraRow($lamp, $brightness),
            $this->extraRow($lamp, $channel)
        ]);

        $extras = $this->fullState($module)['entries'][0]['extras'];

        $this->assertSame(['Saugen', 'Extra ' . $brightness, 'Extra ' . $channel], array_column($extras, 'label'));
        $this->assertSame('buttons', $extras[0]['control']['type']);
        $this->assertSame([
            ['value' => 0, 'caption' => 'Start', 'icon' => '', 'color' => ''],
            ['value' => 1, 'caption' => 'Stop', 'icon' => '', 'color' => ''],
            ['value' => 2, 'caption' => 'Pause', 'icon' => 'pause', 'color' => '#00AA88']
        ], $extras[0]['control']['options']);
        $this->assertSame(['type' => 'slider', 'min' => 0.0, 'max' => 100.0, 'step' => 5.0, 'digits' => 0, 'prefix' => '', 'suffix' => ' %'], $extras[1]['control']);
        $this->assertSame(['type' => 'toggle'], $extras[2]['control']);
        $this->assertSame([1, 40, true], array_column($extras, 'value'));
        // Aufzählung: Text aus der Darstellung. Schieberegler und Boolean ohne Darstellung formatieren die Stubs
        // nicht; dann bildet das Modul den Text selbst.
        $this->assertSame(['Stop', '40 %', 'On'], array_column($extras, 'text'));
    }

    public function testLegacyProfileGivesSliderOrButtons(): void
    {
        IPS_CreateVariableProfile('QSW.Level', VARIABLETYPE_FLOAT);
        IPS_SetVariableProfileValues('QSW.Level', 2700, 6500, 100);
        IPS_SetVariableProfileText('QSW.Level', '', ' K');
        IPS_CreateVariableProfile('QSW.Mode', VARIABLETYPE_INTEGER);
        IPS_SetVariableProfileAssociation('QSW.Mode', 0, 'Eco', '', -1);
        IPS_SetVariableProfileAssociation('QSW.Mode', 1, 'Turbo', '', 0xFF0000);
        $level = $this->createExtra(VARIABLETYPE_FLOAT, 3000.0);
        IPS_SetVariableCustomProfile($level, 'QSW.Level');
        $mode = $this->createExtra(VARIABLETYPE_INTEGER, 0);
        IPS_SetVariableCustomProfile($mode, 'QSW.Mode');
        $lamp = $this->createSwitch('Lamp');
        $module = $this->createQuickSwitch([$this->entry($lamp)], false, null, [$this->extraRow($lamp, $level), $this->extraRow($lamp, $mode)]);

        [$levelExtra, $modeExtra] = $this->fullState($module)['entries'][0]['extras'];

        $this->assertSame(['type' => 'slider', 'min' => 2700.0, 'max' => 6500.0, 'step' => 100.0, 'digits' => 0, 'prefix' => '', 'suffix' => ' K'], $levelExtra['control']);
        $this->assertSame(['Eco', 'Turbo'], array_column($modeExtra['control']['options'], 'caption'));
        $this->assertSame('#FF0000', $modeExtra['control']['options'][1]['color']);
    }

    public function testUnsupportedVariablesAreUnavailable(): void
    {
        $lamp = $this->createSwitch('Lamp');
        $text = $this->createExtra(VARIABLETYPE_STRING, 'x');
        $plainInteger = $this->createExtra(VARIABLETYPE_INTEGER, 3);
        $withoutAction = $this->createExtra(VARIABLETYPE_BOOLEAN, false, null, false);
        $module = $this->createQuickSwitch([$this->entry($lamp)], false, null, [
            $this->extraRow($lamp, $text),
            $this->extraRow($lamp, $plainInteger),
            $this->extraRow($lamp, $withoutAction)
        ]);

        $extras = $this->fullState($module)['entries'][0]['extras'];

        $this->assertSame([false, false, false], array_column($extras, 'available'));
        $this->assertSame([null, null, null], array_column($extras, 'control'));
        $this->assertSame(203, $this->instanceStatus($module));
    }

    // --------- Werte setzen ---------

    public function testValuesAreSetThroughTheAction(): void
    {
        $lamp = $this->createSwitch('Lamp');
        $vacuum = $this->createExtra(VARIABLETYPE_INTEGER, 1, $this->enumeration(self::VACUUM_OPTIONS));
        $brightness = $this->createExtra(VARIABLETYPE_INTEGER, 40, $this->slider(0, 100, 5));
        $temperature = $this->createExtra(VARIABLETYPE_FLOAT, 20.0, $this->slider(5, 30, 0.5, 1));
        $channel = $this->createExtra(VARIABLETYPE_BOOLEAN, false);
        $module = $this->createQuickSwitch([$this->entry($lamp)], false, null, [
            $this->extraRow($lamp, $vacuum),
            $this->extraRow($lamp, $brightness),
            $this->extraRow($lamp, $temperature),
            $this->extraRow($lamp, $channel)
        ]);

        $module->RequestAction('Extra', json_encode(['variable' => $vacuum, 'value' => 2]));
        $module->RequestAction('Extra', json_encode(['variable' => $brightness, 'value' => 75]));
        $module->RequestAction('Extra', json_encode(['variable' => $temperature, 'value' => 21.5]));
        $module->RequestAction('Extra', json_encode(['variable' => $channel, 'value' => true]));

        $this->assertSame(2, GetValueInteger($vacuum));
        $this->assertSame(75, GetValueInteger($brightness));
        $this->assertSame(21.5, GetValueFloat($temperature));
        $this->assertTrue(GetValueBoolean($channel));
    }

    public function testInvalidValuesAreRejectedOrClamped(): void
    {
        $lamp = $this->createSwitch('Lamp');
        $vacuum = $this->createExtra(VARIABLETYPE_INTEGER, 1, $this->enumeration(self::VACUUM_OPTIONS));
        $brightness = $this->createExtra(VARIABLETYPE_INTEGER, 40, $this->slider(0, 100, 5));
        $channel = $this->createExtra(VARIABLETYPE_BOOLEAN, false);
        $module = $this->createQuickSwitch([$this->entry($lamp)], false, null, [
            $this->extraRow($lamp, $vacuum),
            $this->extraRow($lamp, $brightness),
            $this->extraRow($lamp, $channel)
        ]);

        // Kein Wert der Aufzählung, kein Boolean, kein JSON
        $module->RequestAction('Extra', json_encode(['variable' => $vacuum, 'value' => 7]));
        $module->RequestAction('Extra', json_encode(['variable' => $channel, 'value' => 1]));
        $module->RequestAction('Extra', '{broken');
        // Außerhalb des Bereichs: auf das Maximum begrenzt
        $module->RequestAction('Extra', json_encode(['variable' => $brightness, 'value' => 250]));

        $this->assertSame(1, GetValueInteger($vacuum));
        $this->assertFalse(GetValueBoolean($channel));
        $this->assertSame(100, GetValueInteger($brightness));
    }

    public function testOnlyAssignedExtrasAreSet(): void
    {
        $lamp = $this->createSwitch('Lamp');
        $other = $this->createSwitch('Other');
        $assigned = $this->createExtra(VARIABLETYPE_BOOLEAN, false);
        $foreign = $this->createExtra(VARIABLETYPE_BOOLEAN, false);
        $orphan = $this->createExtra(VARIABLETYPE_BOOLEAN, false);
        // $orphan gehört zu einem Schalter, der nicht in der Liste der Aktoren steht.
        $module = $this->createQuickSwitch([$this->entry($lamp)], false, null, [$this->extraRow($lamp, $assigned), $this->extraRow($other, $orphan)]);

        $module->RequestAction('Extra', json_encode(['variable' => $foreign, 'value' => true]));
        $module->RequestAction('Extra', json_encode(['variable' => $orphan, 'value' => true]));
        $module->RequestAction('Extra', json_encode(['variable' => $assigned, 'value' => true]));

        $this->assertFalse(GetValueBoolean($foreign));
        $this->assertFalse(GetValueBoolean($orphan));
        $this->assertTrue(GetValueBoolean($assigned));
        $this->assertSame(203, $this->instanceStatus($module));
    }

    // --------- Zustände und Konfiguration ---------

    public function testStatesContainExtraValues(): void
    {
        $lamp = $this->createSwitch('Lamp', true);
        $brightness = $this->createExtra(VARIABLETYPE_INTEGER, 40, $this->slider(0, 100, 5, 0, ' %'));
        $module = $this->createQuickSwitch([$this->entry($lamp)], false, null, [$this->extraRow($lamp, $brightness)]);

        SetValue($brightness, 65);
        $states = $this->call($module, 'buildStates');

        $this->assertSame([['id' => $lamp, 'available' => true, 'on' => true]], $states['states']);
        $this->assertSame([['id' => $brightness, 'available' => true, 'value' => 65, 'text' => '65 %']], $states['extras']);
    }

    public function testExtrasAreAttachedToEveryEntryOfTheirSwitch(): void
    {
        $lamp = $this->createSwitch('Lamp');
        $fan = $this->createSwitch('Fan');
        $channel = $this->createExtra(VARIABLETYPE_BOOLEAN, false);
        $module = $this->createQuickSwitch([$this->entry($lamp), $this->entry($fan), $this->entry($lamp)], false, null, [$this->extraRow($lamp, $channel)]);

        $this->assertSame([1, 0, 1], array_map('count', array_column($this->fullState($module)['entries'], 'extras')));
        $this->assertSame(IS_ACTIVE, $this->instanceStatus($module));
    }

    public function testUnreadableExtrasListSetsErrorStatusAndKeepsProperty(): void
    {
        $lamp = $this->createSwitch('Lamp');
        $module = $this->createQuickSwitch([$this->entry($lamp)], false, null, '{broken');

        $this->assertSame(203, $this->instanceStatus($module));
        $this->assertSame('{broken', IPS_GetProperty($this->instanceID($module), 'Extras'));
        $this->assertSame([], $this->fullState($module)['entries'][0]['extras']);
    }

    public function testMessagesFollowTheConfiguredExtras(): void
    {
        $lamp = $this->createSwitch('Lamp');
        $channel = $this->createExtra(VARIABLETYPE_BOOLEAN, false);
        $module = $this->createQuickSwitch([$this->entry($lamp)], false, null, [$this->extraRow($lamp, $channel)]);

        $this->assertEqualsCanonicalizing([VM_UPDATE, VM_DELETE], $this->call($module, 'GetMessageList')[$channel]);

        IPS_SetProperty($this->instanceID($module), 'Extras', '[]');
        IPS_ApplyChanges($this->instanceID($module));

        $this->assertArrayNotHasKey($channel, $this->call($module, 'GetMessageList'));
    }

    private function enumeration(array $options): array
    {
        return ['PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION, 'OPTIONS' => json_encode($options)];
    }

    private function slider(float $min, float $max, float $step, int $digits = 0, string $suffix = ''): array
    {
        return ['PRESENTATION' => VARIABLE_PRESENTATION_SLIDER, 'MIN' => $min, 'MAX' => $max, 'STEP_SIZE' => $step, 'DIGITS' => $digits, 'PREFIX' => '', 'SUFFIX' => $suffix];
    }

    private function extraRow(int $switchID, int $variableID, string $label = ''): array
    {
        return ['switchVariable' => $switchID, 'variable' => $variableID, 'label' => $label];
    }
}