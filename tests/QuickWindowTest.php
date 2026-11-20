<?php

declare(strict_types=1);

include_once __DIR__ . '/stubs/autoload.php';

use PHPUnit\Framework\TestCase;

// QuickWindow: Konfiguration, Gesamtzustand aus Reed-Kontakt und Siegenia-Griffen, Verriegeln
class QuickWindowTest extends TestCase
{
    private const MODULE = '{31D4FBBC-4F6B-7E50-5575-DA01D3B76D6D}';

    // Darstellung der Aktionsvariable wie bei den Siegenia-Griffen
    private const LOCK_OPTIONS = [
        ['Value' => 0, 'Caption' => 'Öffnen', 'IconActive' => true, 'IconValue' => 'unlock', 'Color' => -1],
        ['Value' => 2, 'Caption' => 'Verriegeln', 'IconActive' => true, 'IconValue' => 'lock', 'Color' => -1]
    ];

    private int $actionScript = 0;

    protected function setUp(): void
    {
        parent::setUp();
        IPS\Kernel::reset();
        IPS\ModuleLoader::loadLibrary(__DIR__ . '/../library.json');
        $GLOBALS['QWN_FAIL'] = [];
        $GLOBALS['QWN_COMMANDS'] = [];

        $this->actionScript = IPS_CreateScript(0);
        IPS_SetScriptContent($this->actionScript, '<?php
            if (!empty($GLOBALS["QWN_FAIL"][$_IPS["VARIABLE"]])) {
                throw new Exception("Simulierter Gerätefehler");
            }
            $GLOBALS["QWN_COMMANDS"][] = [$_IPS["VARIABLE"], $_IPS["VALUE"]];
            SetValue($_IPS["VARIABLE"], $_IPS["VALUE"]);');
    }

    // --------- Konfiguration ---------

    public function testInstanceWithoutOpeningsIsInactive(): void
    {
        $this->assertSame(IS_INACTIVE, $this->instanceStatus($this->create([])));
    }

    public function testReedOnlyOpeningsAreActive(): void
    {
        $module = $this->create([$this->opening($this->createContact(false)), $this->opening($this->createContact(true), ['kind' => 'door'])]);

        $this->assertSame(IS_ACTIVE, $this->instanceStatus($module));
    }

    public function testContactThatIsNotBooleanSetsErrorStatus(): void
    {
        $module = $this->create([$this->opening($this->createInteger(0))]);

        $this->assertSame(201, $this->instanceStatus($module));
    }

    public function testUnreadableListSetsErrorStatusAndKeepsProperty(): void
    {
        $module = $this->create([], [], '{broken');

        $this->assertSame(202, $this->instanceStatus($module));
        $this->assertSame('{broken', IPS_GetProperty($this->instanceID($module), 'Openings'));
        $this->assertSame([], $this->state($module)['openings']);
    }

    public function testInvalidHandlesSetErrorStatus(): void
    {
        $contact = $this->createContact(false);
        $foreignContact = $this->createContact(false);
        [$position, $lockStatus, $lockAction] = $this->siegenia(1, 1);
        $cases = [
            'Öffnung nicht in der Liste'  => $this->handle($foreignContact, $position),
            'Griffstellung fehlt'         => $this->handle($contact, 0),
            'Nur Verriegelungsstatus'     => $this->handle($contact, $position, $lockStatus),
            'Aktion ohne Aktion'          => $this->handle($contact, $position, $lockStatus, $this->createLockAction(false))
        ];
        foreach ($cases as $name => $handle) {
            $module = $this->create([$this->opening($contact)], [$handle]);
            $this->assertSame(203, $this->instanceStatus($module), $name);
        }

        $valid = $this->create([$this->opening($contact)], [$this->handle($contact, $position), $this->handle($contact, $position, $lockStatus, $lockAction)]);
        $this->assertSame(IS_ACTIVE, $this->instanceStatus($valid));
    }

    public function testMessagesFollowTheConfiguredVariables(): void
    {
        $contact = $this->createContact(false);
        [$position, $lockStatus, $lockAction] = $this->siegenia(1, 1);
        $module = $this->create([$this->opening($contact)], [$this->handle($contact, $position, $lockStatus, $lockAction)]);

        $messages = $this->call($module, 'GetMessageList');
        foreach ([$contact, $position, $lockStatus] as $id) {
            $this->assertEqualsCanonicalizing([VM_UPDATE, VM_DELETE], $messages[$id]);
        }
        $this->assertSame([VM_DELETE], array_values($messages[$lockAction]));

        IPS_SetProperty($this->instanceID($module), 'Openings', '[]');
        IPS_SetProperty($this->instanceID($module), 'Handles', '[]');
        IPS_ApplyChanges($this->instanceID($module));

        $this->assertSame([0], array_keys($this->call($module, 'GetMessageList')));
    }

    // --------- Gesamtzustand ---------

    public function testReedContactDeterminesStateAndCanBeInverted(): void
    {
        $normal = $this->createContact(true);
        $inverted = $this->createContact(true);
        $module = $this->create([$this->opening($normal), $this->opening($inverted, ['invert' => true, 'kind' => 'door'])]);

        [$window, $door] = $this->state($module)['openings'];

        $this->assertSame(['open', 'window-frame-open', 'Open'], [$window['state'], $window['icon'], $window['text']]);
        $this->assertSame(['closed', 'door-closed', 'Closed'], [$door['state'], $door['icon'], $door['text']]);
    }

    public function testStateCombinesContactAndHandles(): void
    {
        $contact = $this->createContact(false);
        [$leftPosition, $leftLock, $leftAction] = $this->siegenia(1, 1);
        [$rightPosition, $rightLock, $rightAction] = $this->siegenia(1, 1);
        $module = $this->create([$this->opening($contact, ['kind' => 'door'])], [
            $this->handle($contact, $leftPosition, $leftLock, $leftAction),
            $this->handle($contact, $rightPosition, $rightLock, $rightAction)
        ]);

        // [Kontakt offen, Griff links, Griff rechts, Verriegelung links, Verriegelung rechts] => Zustand
        // Maßgeblich ist der Kontakt; Griffe präzisieren nur einen offenen Kontakt, Schlösser färben die Öffnung nicht.
        $cases = [
            [false, 1, 1, 1, 1, 'closed'],
            [false, 1, 1, 1, 2, 'closed'],
            [false, 1, 1, 0, 1, 'closed'],
            [false, 5, 0, 2, 2, 'closed'],
            [false, 3, 1, 1, 1, 'closed'],
            [true, 1, 1, 2, 2, 'open'],
            [true, 5, 1, 2, 2, 'tilted'],
            [true, 5, 0, 2, 2, 'open'],
            [true, 5, 3, 2, 2, 'forced'],
            [true, 1, 9, 2, 2, 'open']
        ];
        foreach ($cases as [$open, $left, $right, $leftLockValue, $rightLockValue, $expected]) {
            SetValue($contact, $open);
            SetValue($leftPosition, $left);
            SetValue($rightPosition, $right);
            SetValue($leftLock, $leftLockValue);
            SetValue($rightLock, $rightLockValue);
            $this->assertSame($expected, $this->state($module)['openings'][0]['state'], json_encode([$open, $left, $right, $leftLockValue, $rightLockValue]));
        }
    }

    public function testHandlesWithoutMotorNeverReportLocked(): void
    {
        $contact = $this->createContact(false);
        $module = $this->create([$this->opening($contact)], [$this->handle($contact, $this->createInteger(1))]);

        $opening = $this->state($module)['openings'][0];

        $this->assertSame('closed', $opening['state']);
        $this->assertFalse($opening['handles'][0]['lockable']);
        $this->assertSame(['unlock' => null, 'open' => null, 'lock' => null], $opening['handles'][0]['commands']);
    }

    public function testHandleTextsAndCommandsComeFromPresentations(): void
    {
        $contact = $this->createContact(false);
        [$position, $lockStatus, $lockAction] = $this->siegenia(5, 2);
        IPS_SetVariableCustomPresentation($position, ['PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION, 'OPTIONS' => json_encode([
            ['Value' => 5, 'Caption' => 'Gekippt (Siegenia)']
        ])]);
        $module = $this->create([$this->opening($contact)], [$this->handle($contact, $position, $lockStatus, $lockAction, ['label' => 'Flügel links'])]);

        $handle = $this->state($module)['openings'][0]['handles'][0];

        $this->assertSame('Flügel links', $handle['label']);
        $this->assertSame(['tilted', 'Gekippt (Siegenia)', 'Unlocked'], [$handle['positionState'], $handle['positionText'], $handle['lockText']]);
        $this->assertSame(['value' => 0, 'caption' => 'Öffnen', 'icon' => 'unlock', 'color' => ''], $handle['commands']['unlock']);
        $this->assertNull($handle['commands']['open']);
        $this->assertSame('Verriegeln', $handle['commands']['lock']['caption']);
    }

    public function testOwnIconsAndWarningIcon(): void
    {
        $contact = $this->createContact(true);
        $position = $this->createInteger(1);
        $module = $this->create([$this->opening($contact, ['iconOpen' => 'person-walking', 'iconClosed' => '<b>x</b>'])], [$this->handle($contact, $position)]);

        $this->assertSame('person-walking', $this->state($module)['openings'][0]['icon']);
        SetValue($position, 5);
        $this->assertSame('person-walking', $this->state($module)['openings'][0]['icon']);
        SetValue($position, 3);
        $this->assertSame('triangle-exclamation', $this->state($module)['openings'][0]['icon']);
        SetValue($contact, false);
        $this->assertSame('window-frame', $this->state($module)['openings'][0]['icon']);
    }

    public function testSummaryCountsOpenAndTilted(): void
    {
        $closed = $this->createContact(false);
        $open = $this->createContact(true);
        $tilted = $this->createContact(true);
        $module = $this->create(
            [$this->opening($closed), $this->opening($open), $this->opening($tilted)],
            [$this->handle($tilted, $this->createInteger(5))]
        );

        $this->assertSame('1 open · 1 tilted', $this->state($module)['summary']);
        SetValue($open, false);
        $this->assertSame('1 tilted', $this->call($module, 'buildStates')['summary']);
        SetValue($tilted, false);
        $this->assertSame('All closed', $this->call($module, 'buildStates')['summary']);
    }

    public function testDefaultLabelUsesParentInstance(): void
    {
        $device = IPS_CreateInstance(self::MODULE);
        IPS_SetName($device, 'Bad Fenster');
        $module = $this->create([
            $this->opening($this->createContact(false, $device)),
            $this->opening($this->createContact(false), ['label' => 'Küche'])
        ]);

        $this->assertSame(['Bad Fenster', 'Küche'], array_column($this->state($module)['openings'], 'label'));
    }

    public function testDeletedContactIsShownAsUnavailable(): void
    {
        $contact = $this->createContact(false);
        $module = $this->create([$this->opening($contact, ['label' => 'Bad'])], [$this->handle($contact, $this->createInteger(1))]);

        IPS_DeleteVariable($contact);
        $opening = $this->state($module)['openings'][0];

        $this->assertFalse($opening['available']);
        $this->assertFalse($opening['handles'][0]['available']);
        $this->assertSame('Bad', $opening['label']);
    }

    public function testStateUpdatesKeepOrderAndHandleIds(): void
    {
        $contact = $this->createContact(false);
        [$position, $lockStatus, $lockAction] = $this->siegenia(1, 1);
        $module = $this->create([$this->opening($contact)], [$this->handle($contact, $position, $lockStatus, $lockAction)]);

        $states = $this->call($module, 'buildStates');

        $this->assertSame('states', $states['type']);
        $this->assertSame('closed', $states['openings'][0]['state']);
        $this->assertSame($position, $states['openings'][0]['handles'][0]['id']);
        $this->assertArrayNotHasKey('label', $states['openings'][0]);
    }

    // --------- Verriegeln ---------

    public function testLockAndOpenAreSentToTheActionVariable(): void
    {
        $contact = $this->createContact(false);
        [$position, $lockStatus, $lockAction] = $this->siegenia(1, 2);
        $module = $this->create([$this->opening($contact)], [$this->handle($contact, $position, $lockStatus, $lockAction)]);

        $module->RequestAction('Lock', $lockAction);
        $module->RequestAction('Open', $lockAction);

        $this->assertSame([[$lockAction, 2], [$lockAction, 0]], $GLOBALS['QWN_COMMANDS']);
        // Status und Griffstellung werden nie direkt beschrieben.
        $this->assertSame([2, 1], [GetValueInteger($lockStatus), GetValueInteger($position)]);
    }

    public function testLockIsRejectedUnlessHandleIsClosed(): void
    {
        $contact = $this->createContact(true);
        [$position, $lockStatus, $lockAction] = $this->siegenia(5, 2);
        $module = $this->create([$this->opening($contact)], [$this->handle($contact, $position, $lockStatus, $lockAction)]);

        foreach ([0, 5, 2, 3] as $value) {
            SetValue($position, $value);
            $module->RequestAction('Lock', $lockAction);
        }
        $module->RequestAction('Open', $lockAction);

        $this->assertSame([[$lockAction, 0]], $GLOBALS['QWN_COMMANDS']);
    }

    public function testLockAllLocksOnlyClosedUnlockedHandlesAndContinuesAfterErrors(): void
    {
        $contact = $this->createContact(false);
        [$failingPosition, $failingLock, $failingAction] = $this->siegenia(1, 2);
        [$closedPosition, $closedLock, $closedAction] = $this->siegenia(1, 2);
        [$tiltedPosition, $tiltedLock, $tiltedAction] = $this->siegenia(5, 2);
        [$lockedPosition, $lockedLock, $lockedAction] = $this->siegenia(1, 1);
        $GLOBALS['QWN_FAIL'][$failingAction] = true;
        $module = $this->create([$this->opening($contact)], [
            $this->handle($contact, $failingPosition, $failingLock, $failingAction),
            $this->handle($contact, $closedPosition, $closedLock, $closedAction),
            $this->handle($contact, $tiltedPosition, $tiltedLock, $tiltedAction),
            $this->handle($contact, $lockedPosition, $lockedLock, $lockedAction)
        ]);

        $this->silently(fn () => $module->RequestAction('LockAll', $contact));

        $this->assertSame([[$closedAction, 2]], $GLOBALS['QWN_COMMANDS']);
    }

    public function testForeignHandlesAndOpeningsAreNotOperated(): void
    {
        $contact = $this->createContact(false);
        $foreignContact = $this->createContact(false);
        [$position, $lockStatus, $lockAction] = $this->siegenia(1, 2);
        $foreignAction = $this->createLockAction();
        $module = $this->create([$this->opening($contact)], [$this->handle($contact, $position, $lockStatus, $lockAction)]);

        $module->RequestAction('Lock', $foreignAction);
        $module->RequestAction('LockAll', $foreignContact);

        $this->assertSame([], $GLOBALS['QWN_COMMANDS']);
    }

    public function testHomematicHandlePositions(): void
    {
        $contact = $this->createContact(true);
        $position = $this->createInteger(0);
        $module = $this->create([$this->opening($contact)], [$this->handle($contact, $position, 0, 0, ['handleType' => 'homematic'])]);

        // Kontakt offen: der Griff präzisiert den Zustand der Öffnung.
        $expected = [0 => ['open', 'closed', 'Closed'], 1 => ['tilted', 'tilted', 'Tilted'], 2 => ['open', 'open', 'Open'], 3 => ['open', 'fault', 'Unknown']];
        foreach ($expected as $value => [$opening, $handle, $text]) {
            SetValue($position, $value);
            $tile = $this->state($module)['openings'][0];
            $this->assertSame([$opening, $handle, $text], [$tile['state'], $tile['handles'][0]['positionState'], $tile['handles'][0]['positionText']], 'Wert ' . $value);
        }
    }

    public function testDoorLockWithoutHandleRespectsContact(): void
    {
        $contact = $this->createContact(false);
        $lockStatus = $this->createInteger(2, 'Verriegelungsstatus');
        $lockAction = $this->createLockAction();
        $module = $this->create([$this->opening($contact, ['kind' => 'door'])], [$this->handle($contact, 0, $lockStatus, $lockAction)]);

        $this->assertSame(IS_ACTIVE, $this->instanceStatus($module));
        $door = $this->state($module)['openings'][0];
        // Das Schloss färbt nur seinen Button; die Tür richtet sich nach dem Kontakt.
        $this->assertSame(['closed', 'door-closed', 'Closed'], [$door['state'], $door['icon'], $door['text']]);
        $this->assertSame([2, 'Unlocked'], [$door['handles'][0]['lockStatus'], $door['handles'][0]['lockText']]);
        $this->assertFalse($door['handles'][0]['hasPosition']);
        $this->assertTrue($door['handles'][0]['lockAllowed']);

        // Offene Tür: nicht verriegeln.
        SetValue($contact, true);
        $this->assertFalse($this->state($module)['openings'][0]['handles'][0]['lockAllowed']);
        $module->RequestAction('Lock', $lockAction);
        $this->assertSame([], $GLOBALS['QWN_COMMANDS']);

        SetValue($contact, false);
        $module->RequestAction('Toggle', $lockAction);
        $this->assertSame([[$lockAction, 2]], $GLOBALS['QWN_COMMANDS']);
        SetValue($lockStatus, 1);
        $door = $this->state($module)['openings'][0];
        $this->assertSame(['closed', 1], [$door['state'], $door['handles'][0]['lockStatus']]);
    }

    public function testToggleUnlocksWithUnlockCommandWhenOffered(): void
    {
        $contact = $this->createContact(false);
        $knob = [
            ['Value' => 0, 'Caption' => 'Öffnen'],
            ['Value' => 1, 'Caption' => 'Entriegeln'],
            ['Value' => 2, 'Caption' => 'Verriegeln']
        ];
        $knobStatus = $this->createInteger(1);
        $knobAction = $this->createLockAction(true, $knob);
        [$position, $handleStatus, $handleAction] = $this->siegenia(1, 1);
        $module = $this->create([$this->opening($contact, ['kind' => 'door'])], [
            $this->handle($contact, 0, $knobStatus, $knobAction),
            $this->handle($contact, $position, $handleStatus, $handleAction)
        ]);

        $module->RequestAction('Toggle', $knobAction);
        $module->RequestAction('Toggle', $handleAction);
        $module->RequestAction('Unlock', $handleAction);

        // Knauf: Entriegeln (1); Siegenia ohne Entriegeln: Öffnen (0); Entriegeln wird dort nicht angeboten.
        $this->assertSame([[$knobAction, 1], [$handleAction, 0]], $GLOBALS['QWN_COMMANDS']);
        $commands = $this->state($module)['openings'][0]['handles'][0]['commands'];
        $this->assertSame([1, 0, 2], [$commands['unlock']['value'], $commands['open']['value'], $commands['lock']['value']]);
    }

    public function testUnknownActionThrows(): void
    {
        $module = $this->create([]);

        $this->expectException(Exception::class);
        $module->RequestAction('Unknown', 0);
    }

    public function testTileContainsEscapedInitialState(): void
    {
        $module = $this->create([$this->opening($this->createContact(false), ['label' => '<b>Bad</b> & "WC"'])]);

        $html = $module->GetVisualizationTile();

        $this->assertStringNotContainsString('__QUICKWINDOW_INITIAL_STATE__', $html);
        $this->assertStringNotContainsString('<b>Bad</b>', $html);
        $this->assertStringContainsString('<script src="/icons.js"></script>', $html);
    }

    // --------- Hilfen ---------

    private function createContact(bool $value, int $parentID = 0): int
    {
        $id = IPS_CreateVariable(VARIABLETYPE_BOOLEAN);
        IPS_SetName($id, 'Kontakt');
        SetValue($id, $value);
        if ($parentID > 0) {
            IPS_SetParent($id, $parentID);
        }
        return $id;
    }

    private function createInteger(int $value, string $name = 'Wert'): int
    {
        $id = IPS_CreateVariable(VARIABLETYPE_INTEGER);
        IPS_SetName($id, $name);
        SetValue($id, $value);
        return $id;
    }

    private function createLockAction(bool $withAction = true, array $options = self::LOCK_OPTIONS): int
    {
        $id = $this->createInteger(2, 'Türschloss');
        IPS_SetVariableCustomPresentation($id, ['PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION, 'OPTIONS' => json_encode($options)]);
        if ($withAction) {
            IPS_SetVariableCustomAction($id, $this->actionScript);
        }
        return $id;
    }

    private function opening(int $contactID, array $overrides = []): array
    {
        return array_merge([
            'contactVariable' => $contactID,
            'invert'          => false,
            'kind'            => 'window',
            'label'           => '',
            'showLabel'       => true,
            'iconClosed'      => '',
            'iconOpen'        => '',
            'iconTilted'      => ''
        ], $overrides);
    }

    private function handle(int $contactID, int $positionID, int $lockStatusID = 0, int $lockActionID = 0, array $overrides = []): array
    {
        return array_merge([
            'contactVariable'    => $contactID,
            'positionVariable'   => $positionID,
            'lockStatusVariable' => $lockStatusID,
            'lockActionVariable' => $lockActionID,
            'label'              => '',
            'confirmOpen'        => false
        ], $overrides);
    }

    /**
     * Siegenia-Griff mit Motor: [Griffstellung, Verriegelungsstatus, Aktion]
     *
     * @return array{int, int, int}
     */
    private function siegenia(int $position, int $lockStatus): array
    {
        return [$this->createInteger($position, 'Türstatus'), $this->createInteger($lockStatus, 'Verriegelungsstatus'), $this->createLockAction()];
    }

    private function create(array $openings, array $handles = [], ?string $rawOpenings = null): QuickWindow
    {
        $id = IPS_CreateInstance(self::MODULE);
        IPS_SetProperty($id, 'Openings', $rawOpenings ?? json_encode($openings));
        IPS_SetProperty($id, 'Handles', json_encode($handles));
        IPS_ApplyChanges($id);
        return IPS\InstanceManager::getInstanceInterface($id);
    }

    private function instanceID(QuickWindow $module): int
    {
        return (new ReflectionProperty($module, 'InstanceID'))->getValue($module);
    }

    private function instanceStatus(QuickWindow $module): int
    {
        return IPS_GetInstance($this->instanceID($module))['InstanceStatus'];
    }

    private function call(object $object, string $method, mixed ...$arguments): mixed
    {
        return (new ReflectionMethod($object, $method))->invoke($object, ...$arguments);
    }

    private function state(QuickWindow $module): array
    {
        return $this->call($module, 'buildFullState');
    }

    private function silently(callable $callback): void
    {
        // Die Skriptausführung der Stubs lässt bei einer Ausnahme ihren Ausgabepuffer offen; hier wieder schließen.
        $level = ob_get_level();
        $callback();
        while (ob_get_level() > $level) {
            ob_end_clean();
        }
    }
}
