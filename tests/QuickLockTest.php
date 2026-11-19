<?php

declare(strict_types=1);

include_once __DIR__ . '/QuickLockTestCase.php';

// QuickLock: Konfiguration, Befehle und Kachelzustand
class QuickLockTest extends QuickLockTestCase
{
    // --------- Konfiguration ---------

    public function testInstanceWithoutEntriesIsInactive(): void
    {
        $this->assertSame(IS_INACTIVE, $this->instanceStatus($this->createQuickLock([])));
    }

    public function testInstanceWithValidEntriesIsActive(): void
    {
        $module = $this->createQuickLock([$this->entry($this->createStatus(1), $this->createAction(self::KNOB_OPTIONS))]);

        $this->assertSame(IS_ACTIVE, $this->instanceStatus($module));
    }

    public function testActionVariableWithoutActionSetsErrorStatus(): void
    {
        $module = $this->createQuickLock([$this->entry($this->createStatus(1), $this->createAction(self::KNOB_OPTIONS, false))]);

        $this->assertSame(201, $this->instanceStatus($module));
    }

    public function testMissingStatusVariableSetsErrorStatus(): void
    {
        $module = $this->createQuickLock([$this->entry(0, $this->createAction(self::KNOB_OPTIONS))]);

        $this->assertSame(201, $this->instanceStatus($module));
    }

    public function testUnreadableListSetsErrorStatusAndKeepsProperty(): void
    {
        $module = $this->createQuickLock([], '{broken');

        $this->assertSame(202, $this->instanceStatus($module));
        $this->assertSame('{broken', IPS_GetProperty($this->instanceID($module), 'Entries'));
        $this->assertSame([], $this->state($module)['entries']);
    }

    public function testMessagesFollowTheConfiguredVariables(): void
    {
        $status = $this->createStatus(1);
        $action = $this->createAction(self::KNOB_OPTIONS);
        $module = $this->createQuickLock([$this->entry($status, $action)]);

        $messages = $this->call($module, 'GetMessageList');
        $this->assertEqualsCanonicalizing([VM_UPDATE, VM_DELETE], $messages[$status]);
        $this->assertSame([VM_DELETE], array_values($messages[$action]));

        IPS_SetProperty($this->instanceID($module), 'Entries', '[]');
        IPS_ApplyChanges($this->instanceID($module));

        $this->assertSame([0], array_keys($this->call($module, 'GetMessageList')));
    }

    // --------- Befehle aus der Darstellung ---------

    public function testCommandsAreReadFromEnumerationPresentation(): void
    {
        $module = $this->createQuickLock([
            $this->entry($this->createStatus(1), $this->createAction(self::HANDLE_OPTIONS)),
            $this->entry($this->createStatus(1), $this->createAction(self::KNOB_OPTIONS))
        ]);

        [$handle, $knob] = $this->state($module)['entries'];

        $this->assertSame([0, 2], $this->commandValues($handle));
        $this->assertSame([0, 1, 2], $this->commandValues($knob));
        $this->assertSame(['value' => 1, 'caption' => 'Entriegeln', 'icon' => 'unlock', 'color' => '#FF0000'], $knob['commands'][1]);
    }

    public function testCommandsAreReadFromLegacyProfile(): void
    {
        IPS_CreateVariableProfile('QLK.Test', VARIABLETYPE_INTEGER);
        IPS_SetVariableProfileAssociation('QLK.Test', 0, 'Auf', 'door-open', -1);
        IPS_SetVariableProfileAssociation('QLK.Test', 1, 'Aufschließen', 'lock-open', 0x123456);
        IPS_SetVariableProfileAssociation('QLK.Test', 2, 'Zu', 'lock', -1);
        $action = $this->createAction(null);
        IPS_SetVariableCustomProfile($action, 'QLK.Test');
        $module = $this->createQuickLock([$this->entry($this->createStatus(2), $action)]);

        $commands = $this->state($module)['entries'][0]['commands'];

        $this->assertSame(['Auf', 'Aufschließen', 'Zu'], array_column($commands, 'caption'));
        $this->assertSame(['', '#123456', ''], array_column($commands, 'color'));
    }

    public function testWithoutPresentationOnlyOpenAndLockAreOffered(): void
    {
        $module = $this->createQuickLock([$this->entry($this->createStatus(1), $this->createAction(null))]);

        $tile = $this->state($module)['entries'][0];

        $this->assertSame([0, 2], $this->commandValues($tile));
        $this->assertSame(['Open', 'Lock'], array_column($tile['commands'], 'caption'));
    }

    // --------- Bedienen ---------

    public function testCommandsAreSentToTheActionVariable(): void
    {
        $status = $this->createStatus(2);
        $action = $this->createAction(self::KNOB_OPTIONS);
        $module = $this->createQuickLock([$this->entry($status, $action)]);

        $module->RequestAction('Lock', $action);
        $module->RequestAction('Unlock', $action);
        $module->RequestAction('Open', $action);

        $this->assertSame([[$action, 2], [$action, 1], [$action, 0]], $GLOBALS['QLK_COMMANDS']);
        // Die Statusvariable wird nie direkt beschrieben.
        $this->assertSame(2, GetValueInteger($status));
    }

    public function testUnlockIsRejectedForLockWithoutUnlockCommand(): void
    {
        $action = $this->createAction(self::HANDLE_OPTIONS);
        $module = $this->createQuickLock([$this->entry($this->createStatus(1), $action)]);

        $module->RequestAction('Unlock', $action);

        $this->assertSame([], $GLOBALS['QLK_COMMANDS']);
    }

    public function testToggleUnlocksLockedDoorAndLocksOtherwise(): void
    {
        $knobStatus = $this->createStatus(1);
        $knob = $this->createAction(self::KNOB_OPTIONS);
        $handleStatus = $this->createStatus(1);
        $handle = $this->createAction(self::HANDLE_OPTIONS);
        $module = $this->createQuickLock([$this->entry($knobStatus, $knob), $this->entry($handleStatus, $handle)]);

        // Verriegelt: Knauf wird entriegelt (ohne Falle), Klinke geöffnet.
        $module->RequestAction('Toggle', $knob);
        $module->RequestAction('Toggle', $handle);
        // Entriegelt, entsperrt bzw. nicht vollständig verriegelt: verriegeln.
        foreach ([2, 3, 0] as $value) {
            SetValue($knobStatus, $value);
            $module->RequestAction('Toggle', $knob);
        }

        $this->assertSame([[$knob, 1], [$handle, 0], [$knob, 2], [$knob, 2], [$knob, 2]], $GLOBALS['QLK_COMMANDS']);
    }

    public function testForeignOrInvalidLocksAreNotOperated(): void
    {
        $assigned = $this->createAction(self::KNOB_OPTIONS);
        $foreign = $this->createAction(self::KNOB_OPTIONS);
        $withoutAction = $this->createAction(self::KNOB_OPTIONS, false);
        $module = $this->createQuickLock([$this->entry($this->createStatus(1), $assigned), $this->entry($this->createStatus(1), $withoutAction)]);

        $module->RequestAction('Lock', $foreign);
        $module->RequestAction('Toggle', $foreign);
        $module->RequestAction('Lock', $withoutAction);

        $this->assertSame([], $GLOBALS['QLK_COMMANDS']);
    }

    public function testFailingCommandIsHandled(): void
    {
        $action = $this->createAction(self::KNOB_OPTIONS);
        $GLOBALS['QLK_FAIL'][$action] = true;
        $module = $this->createQuickLock([$this->entry($this->createStatus(2), $action)]);

        // Die Skriptausführung der Stubs lässt bei einer Ausnahme ihren Ausgabepuffer offen; hier wieder schließen.
        $level = ob_get_level();
        $module->RequestAction('Lock', $action);
        while (ob_get_level() > $level) {
            ob_end_clean();
        }

        $this->assertSame([], $GLOBALS['QLK_COMMANDS']);
        $this->assertSame(1, GetValueInteger($action));
    }

    public function testUnknownActionThrows(): void
    {
        $module = $this->createQuickLock([]);

        $this->expectException(Exception::class);
        $module->RequestAction('Unknown', 0);
    }

    // --------- Kachelzustand ---------

    public function testStateGraphicFollowsLockState(): void
    {
        $status = $this->createStatus(1);
        $module = $this->createQuickLock([$this->entry($status, $this->createAction(self::KNOB_OPTIONS))]);

        $expected = [
            1  => ['locked', 'lock', '#00FF00', 'Locked'],
            2  => ['unlocked', 'unlock', '#FF0000', 'Unlocked'],
            3  => ['unlatched', 'unlock', '#FFFF00', 'Unlatched'],
            0  => ['notLocked', 'triangle-exclamation', '', 'Not fully locked'],
            7  => ['unknown', 'question', '', 'Unknown']
        ];
        foreach ($expected as $value => [$state, $icon, $color, $text]) {
            SetValue($status, $value);
            $tile = $this->state($module)['entries'][0];
            $this->assertSame([$value, $state, $icon, $color, $text], [$tile['status'], $tile['state'], $tile['icon'], $tile['color'], $tile['text']], 'Status ' . $value);
        }
    }

    public function testHandleLockUsesOpenGraphicWhenUnlocked(): void
    {
        $module = $this->createQuickLock([$this->entry($this->createStatus(2), $this->createAction(self::HANDLE_OPTIONS))]);

        $tile = $this->state($module)['entries'][0];

        $this->assertSame(['unlocked', 'unlock', '#FF0000'], [$tile['state'], $tile['icon'], $tile['color']]);
    }

    public function testStatusTextIsTakenFromPresentationOfStatusVariable(): void
    {
        $status = $this->createStatus(1);
        IPS_SetVariableCustomPresentation($status, ['PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION, 'OPTIONS' => json_encode([
            ['Value' => 1, 'Caption' => 'Zu & sicher']
        ])]);
        $module = $this->createQuickLock([$this->entry($status, $this->createAction(self::KNOB_OPTIONS))]);

        $this->assertSame('Zu & sicher', $this->state($module)['entries'][0]['text']);
    }

    public function testUnreadableStatusPresentationFallsBackToOwnText(): void
    {
        // Die Stubs können die Wertanzeige nicht formatieren und werfen eine Ausnahme.
        $status = $this->createStatus(2);
        IPS_SetVariableCustomPresentation($status, ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION]);
        $module = $this->createQuickLock([$this->entry($status, $this->createAction(self::KNOB_OPTIONS))]);

        $this->assertSame('Unlocked', $this->state($module)['entries'][0]['text']);
    }

    public function testDeletedVariableIsShownAsUnavailable(): void
    {
        $status = $this->createStatus(1);
        $module = $this->createQuickLock([$this->entry($status, $this->createAction(self::KNOB_OPTIONS), ['label' => 'Haustür'])]);

        IPS_DeleteVariable($status);
        $tile = $this->state($module)['entries'][0];

        $this->assertFalse($tile['available']);
        $this->assertNull($tile['status']);
        $this->assertSame('Haustür', $tile['label']);
    }

    public function testDefaultLabelUsesParentInstanceOfActionVariable(): void
    {
        $device = IPS_CreateInstance(self::MODULE);
        IPS_SetName($device, 'Haustür (Smart Lock) - Matter');
        $module = $this->createQuickLock([
            $this->entry($this->createStatus(1, $device), $this->createAction(self::KNOB_OPTIONS, true, $device)),
            $this->entry($this->createStatus(1), $this->createAction(self::HANDLE_OPTIONS), ['label' => 'Keller'])
        ]);

        $this->assertSame(['Haustür (Smart Lock) - Matter', 'Keller'], array_column($this->state($module)['entries'], 'label'));
    }

    public function testRowsFromOlderOrForeignFormatsAreReadWithDefaults(): void
    {
        $status = $this->createStatus(1);
        $action = $this->createAction(self::KNOB_OPTIONS);
        $module = $this->createQuickLock([], json_encode([['statusVariable' => $status, 'actionVariable' => $action], 'not an object']));

        $entries = $this->state($module)['entries'];

        $this->assertCount(1, $entries);
        $this->assertTrue($entries[0]['showLabel']);
        $this->assertTrue($entries[0]['confirmOpen']);
        $this->assertFalse($entries[0]['confirmUnlock']);
    }

    // --------- Eigene Icons und Bilder ---------

    public function testOwnIconsReplacePresentationIcons(): void
    {
        $status = $this->createStatus(1);
        $module = $this->createQuickLock([$this->entry($status, $this->createAction(self::KNOB_OPTIONS), [
            'iconLocked'   => 'door-closed',
            'iconUnlocked' => 'door-open'
        ])]);

        $expected = [1 => ['door-closed', '#00FF00'], 2 => ['door-open', '#FF0000'], 3 => ['door-open', '#FFFF00'], 0 => ['triangle-exclamation', '']];
        foreach ($expected as $value => [$icon, $color]) {
            SetValue($status, $value);
            $tile = $this->state($module)['entries'][0];
            // Die Farbe kommt weiterhin aus der Darstellung; entsperrt ohne eigenes Icon nutzt das für entriegelt.
            $this->assertSame([$icon, $color], [$tile['icon'], $tile['color']], 'Status ' . $value);
        }
    }

    public function testOwnUnlatchedIconAndInvalidIconNames(): void
    {
        $status = $this->createStatus(3);
        $module = $this->createQuickLock([
            $this->entry($status, $this->createAction(self::KNOB_OPTIONS), ['iconUnlatched' => 'person-walking', 'iconUnlocked' => 'door-open']),
            $this->entry($status, $this->createAction(self::KNOB_OPTIONS), ['iconUnlatched' => '<b>x</b>', 'iconUnlocked' => 42])
        ]);

        $this->assertSame(['person-walking', 'unlock'], array_column($this->state($module)['entries'], 'icon'));
    }

    public function testImagesAreSentAsDataUrls(): void
    {
        $locked = $this->createImage(self::PNG);
        $unlocked = $this->createImage(self::JPEG);
        $module = $this->createQuickLock([
            $this->entry($this->createStatus(1), $this->createAction(self::KNOB_OPTIONS), ['imageLocked' => $locked, 'imageUnlocked' => $unlocked]),
            $this->entry($this->createStatus(1), $this->createAction(self::HANDLE_OPTIONS))
        ]);

        [$withImages, $withoutImages] = $this->state($module)['entries'];

        $this->assertSame([
            'locked'    => 'data:image/png;base64,' . base64_encode(self::PNG),
            'unlocked'  => 'data:image/jpeg;base64,' . base64_encode(self::JPEG),
            'unlatched' => ''
        ], $withImages['images']);
        $this->assertSame(['locked' => '', 'unlocked' => '', 'unlatched' => ''], $withoutImages['images']);
    }

    public function testInvalidImagesAreIgnored(): void
    {
        $module = $this->createQuickLock([$this->entry($this->createStatus(1), $this->createAction(self::KNOB_OPTIONS), [
            'imageLocked'    => $this->createImage('no image'),
            'imageUnlocked'  => $this->createImage(self::PNG . str_repeat('x', 524288)),
            'imageUnlatched' => 99999
        ])]);

        $this->assertSame(['locked' => '', 'unlocked' => '', 'unlatched' => ''], $this->state($module)['entries'][0]['images']);
    }

    public function testStateUpdatesDoNotContainImages(): void
    {
        $status = $this->createStatus(1);
        $action = $this->createAction(self::KNOB_OPTIONS);
        $module = $this->createQuickLock([$this->entry($status, $action, ['imageLocked' => $this->createImage(self::PNG)])]);

        SetValue($status, 2);
        $states = $this->call($module, 'buildStates');

        $this->assertSame('states', $states['type']);
        $this->assertSame([
            ['id' => $action, 'available' => true, 'status' => 2, 'state' => 'unlocked', 'text' => 'Unlocked', 'icon' => 'unlock', 'color' => '#FF0000']
        ], $states['states']);
    }

    public function testMediaMessagesFollowTheConfiguredImages(): void
    {
        $image = $this->createImage(self::PNG);
        $module = $this->createQuickLock([$this->entry($this->createStatus(1), $this->createAction(self::KNOB_OPTIONS), ['imageUnlatched' => $image])]);

        $this->assertEqualsCanonicalizing([MM_UPDATE, MM_CHANGEFILE, MM_DELETE], $this->call($module, 'GetMessageList')[$image]);
    }

    public function testTileContainsEscapedInitialState(): void
    {
        $module = $this->createQuickLock([$this->entry($this->createStatus(1), $this->createAction(self::KNOB_OPTIONS), ['label' => '<b>Tür</b> & "Co"'])]);

        $html = $module->GetVisualizationTile();

        $this->assertStringNotContainsString('__QUICKLOCK_INITIAL_STATE__', $html);
        $this->assertStringNotContainsString('<b>Tür</b>', $html);
        $this->assertStringContainsString('<script src="/icons.js"></script>', $html);
    }
}