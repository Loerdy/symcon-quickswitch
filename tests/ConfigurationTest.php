<?php

declare(strict_types=1);

include_once __DIR__ . '/QuickSwitchTestCase.php';

// Instanzstatus, Auswertung der Eintragsliste und Nachrichtenregistrierung
class ConfigurationTest extends QuickSwitchTestCase
{
    public function testInstanceWithoutEntriesIsInactive(): void
    {
        $this->assertSame(IS_INACTIVE, $this->instanceStatus($this->createQuickSwitch([])));
    }

    public function testInstanceWithValidEntriesIsActive(): void
    {
        $module = $this->createQuickSwitch([$this->entry($this->createSwitch('Light'))]);

        $this->assertSame(IS_ACTIVE, $this->instanceStatus($module));
    }

    public function testEntryWithoutActionSetsErrorStatus(): void
    {
        $module = $this->createQuickSwitch([
            $this->entry($this->createSwitch('Light')),
            $this->entry($this->createSwitch('Sensor', false, false))
        ]);

        $this->assertSame(201, $this->instanceStatus($module));
    }

    public function testEntryWithoutVariableSetsErrorStatus(): void
    {
        $module = $this->createQuickSwitch([$this->entry(0)]);

        $this->assertSame(201, $this->instanceStatus($module));
    }

    public function testUnreadableListSetsErrorStatusAndKeepsProperty(): void
    {
        $module = $this->createQuickSwitch([], false, '{broken');

        $this->assertSame(202, $this->instanceStatus($module));
        $this->assertSame('{broken', IPS_GetProperty($this->instanceID($module), 'Entries'));
        $this->assertSame([], $this->fullState($module)['entries']);
    }

    public function testRowsFromOlderOrForeignFormatsAreReadWithDefaults(): void
    {
        $light = $this->createSwitch('Light');
        $module = $this->createQuickSwitch([], false, json_encode([['variable' => $light], 'not an object']));

        $entries = $this->fullState($module)['entries'];

        $this->assertCount(1, $entries);
        $this->assertTrue($entries[0]['showLabel']);
        $this->assertTrue($entries[0]['includeInAll']);
        $this->assertSame('', $entries[0]['color']);
    }

    public function testMessagesFollowTheConfiguredObjects(): void
    {
        $light = $this->createSwitch('Light');
        $image = $this->createImage(self::PNG);
        $module = $this->createQuickSwitch([$this->entry($light, ['imageOn' => $image])]);

        $messages = $this->call($module, 'GetMessageList');
        $this->assertEqualsCanonicalizing([VM_UPDATE, VM_DELETE], $messages[$light]);
        $this->assertEqualsCanonicalizing([MM_UPDATE, MM_CHANGEFILE, MM_DELETE], $messages[$image]);
        $this->assertContains(IPS_KERNELSTARTED, $messages[0]);

        // Nach Entfernen des Eintrags bleiben nur die Kernel-Nachrichten registriert.
        IPS_SetProperty($this->instanceID($module), 'Entries', '[]');
        IPS_ApplyChanges($this->instanceID($module));

        $this->assertSame([0], array_keys($this->call($module, 'GetMessageList')));
    }
}
