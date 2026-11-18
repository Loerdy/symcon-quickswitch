<?php

declare(strict_types=1);

include_once __DIR__ . '/QuickSwitchTestCase.php';

// Zustand für die Kachel: Beschriftung, Verfügbarkeit, Bilder und HTML-Vorlage
class VisualizationTest extends QuickSwitchTestCase
{
    public function testStateReflectsVariableValues(): void
    {
        $on = $this->createSwitch('On', true);
        $off = $this->createSwitch('Off', false);
        $module = $this->createQuickSwitch([$this->entry($on), $this->entry($off)]);

        $states = $this->call($module, 'buildStates');

        $this->assertSame('states', $states['type']);
        $this->assertSame([
            ['id' => $on, 'available' => true, 'on' => true],
            ['id' => $off, 'available' => true, 'on' => false]
        ], $states['states']);
    }

    public function testDeletedVariableIsShownAsUnavailable(): void
    {
        $light = $this->createSwitch('Light', true);
        $module = $this->createQuickSwitch([$this->entry($light, ['label' => 'Ceiling'])]);

        IPS_DeleteVariable($light);
        $entry = $this->fullState($module)['entries'][0];

        $this->assertFalse($entry['available']);
        $this->assertFalse($entry['on']);
        $this->assertSame('Ceiling', $entry['label']);
    }

    public function testDefaultLabelUsesParentInstanceName(): void
    {
        $device = IPS_CreateInstance(self::MODULE);
        IPS_SetName($device, 'Ceiling light');
        $status = $this->createSwitch('Status', false, true, $device);
        $loose = $this->createSwitch('Hall');
        $module = $this->createQuickSwitch([$this->entry($status), $this->entry($loose), $this->entry($loose, ['label' => 'Own'])]);

        $labels = array_column($this->fullState($module)['entries'], 'label');

        $this->assertSame(['Ceiling light', 'Hall', 'Own'], $labels);
    }

    public function testColorIsConvertedToHex(): void
    {
        $light = $this->createSwitch('Light');
        $module = $this->createQuickSwitch([$this->entry($light, ['color' => 0x00CC88]), $this->entry($light)]);

        $entries = $this->fullState($module)['entries'];

        $this->assertSame('#00CC88', $entries[0]['color']);
        $this->assertSame('', $entries[1]['color']);
    }

    public function testImagesAreSentAsDataUrls(): void
    {
        $light = $this->createSwitch('Light');
        $on = $this->createImage(self::PNG);
        $off = $this->createImage(self::JPEG);
        $module = $this->createQuickSwitch([$this->entry($light, ['imageOn' => $on, 'imageOff' => $off]), $this->entry($light, ['imageOn' => $on])]);

        $entries = $this->fullState($module)['entries'];

        $this->assertSame('data:image/png;base64,' . base64_encode(self::PNG), $entries[0]['imageOn']);
        $this->assertSame('data:image/jpeg;base64,' . base64_encode(self::JPEG), $entries[0]['imageOff']);
        // Nur An-Bild: kein Aus-Bild, die Kachel stellt das An-Bild grau dar.
        $this->assertSame('', $entries[1]['imageOff']);
    }

    public function testInvalidImagesAreIgnored(): void
    {
        $light = $this->createSwitch('Light');
        $text = $this->createImage('no image');
        $large = $this->createImage(self::PNG . str_repeat('x', 524288));
        $module = $this->createQuickSwitch([
            $this->entry($light, ['imageOn' => $text]),
            $this->entry($light, ['imageOn' => $large]),
            $this->entry($light, ['imageOn' => 99999])
        ]);

        $this->assertSame(['', '', ''], array_column($this->fullState($module)['entries'], 'imageOn'));
    }

    public function testAllButtonSettingsArePassed(): void
    {
        $module = $this->createQuickSwitch([$this->entry($this->createSwitch('Light'))], true);

        $this->assertSame(['show' => true, 'label' => '', 'icon' => 'power-off'], $this->fullState($module)['all']);
    }

    public function testTileContainsEscapedInitialState(): void
    {
        $light = $this->createSwitch('Light');
        $module = $this->createQuickSwitch([$this->entry($light, ['label' => '<b>Hall</b> & "Co"'])]);

        $html = $module->GetVisualizationTile();

        $this->assertStringNotContainsString('__QUICKSWITCH_INITIAL_STATE__', $html);
        $this->assertStringNotContainsString('<b>Hall</b>', $html);
        $this->assertStringContainsString(trim(json_encode('<b>Hall</b>', JSON_HEX_TAG), '"'), $html);
        $this->assertStringContainsString('<script src="/icons.js"></script>', $html);
    }
}
