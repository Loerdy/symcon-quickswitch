<?php

declare(strict_types=1);

// Auswahlwerte und Wertebereich aus der Darstellung bzw. dem Profil einer Variable (QuickSwitch und QuickLock)
trait QuickSwitchPresentation
{
    // Parameter der Darstellung; Werte einer Vorlage ergänzen fehlende Parameter.
    private function presentationParameters(int $variableId): array
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
        if (!is_array($presentation)) {
            return [];
        }
        $template = $presentation['TEMPLATE'] ?? null;
        if (is_string($template) && $template !== '' && IPS_TemplateExists($template)) {
            $values = IPS_GetTemplate($template)['Values'] ?? [];
            if (is_array($values)) {
                $presentation += $values;
            }
        }
        return $presentation;
    }

    private function presentationProfile(array $presentation): ?array
    {
        if (($presentation['PRESENTATION'] ?? '') !== VARIABLE_PRESENTATION_LEGACY) {
            return null;
        }
        $name = is_string($presentation['PROFILE'] ?? null) ? $presentation['PROFILE'] : '';
        return $name !== '' && IPS_VariableProfileExists($name) ? IPS_GetVariableProfile($name) : null;
    }

    /**
     * Auswahlwerte einer Aufzählung (auch über eine Vorlage) oder der Assoziationen des Profils.
     *
     * @return array<int, array{value: int|float, caption: string, icon: string, color: string}>
     */
    private function enumerationOptions(int $variableId): array
    {
        $presentation = $this->presentationParameters($variableId);
        $profile = $this->presentationProfile($presentation);
        if ($profile !== null) {
            $rows = [];
            foreach ($profile['Associations'] ?? [] as $association) {
                if (is_array($association)) {
                    $rows[] = ['Value' => $association['Value'] ?? null, 'Caption' => $association['Name'] ?? '', 'IconActive' => true, 'IconValue' => $association['Icon'] ?? '', 'Color' => $association['Color'] ?? -1];
                }
            }
        } else {
            $raw = $presentation['OPTIONS'] ?? null;
            $rows = is_string($raw) ? json_decode($raw, true) : $raw;
        }
        if (!is_array($rows)) {
            return [];
        }

        $options = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !is_numeric($row['Value'] ?? null)) {
                continue;
            }
            $options[] = [
                'value'   => $row['Value'] + 0,
                'caption' => is_string($row['Caption'] ?? null) ? $row['Caption'] : '',
                'icon'    => ($row['IconActive'] ?? false) === true && is_string($row['IconValue'] ?? null) && preg_match('/^[a-z0-9-]+$/', $row['IconValue']) === 1 ? $row['IconValue'] : '',
                'color'   => $this->hexColor($row['Color'] ?? -1)
            ];
        }
        return $options;
    }

    /**
     * Wertebereich eines Schiebereglers (Darstellung Schieberegler oder Profil ohne Assoziationen).
     *
     * @return array{min: float, max: float, step: float, digits: int, prefix: string, suffix: string}|null
     */
    private function sliderRange(int $variableId): ?array
    {
        $presentation = $this->presentationParameters($variableId);
        $profile = $this->presentationProfile($presentation);
        if ($profile !== null) {
            if (!empty($profile['Associations'])) {
                return null;
            }
            $values = [$profile['MinValue'] ?? null, $profile['MaxValue'] ?? null, $profile['StepSize'] ?? null, $profile['Digits'] ?? 0, $profile['Prefix'] ?? '', $profile['Suffix'] ?? ''];
        } elseif (($presentation['PRESENTATION'] ?? '') === VARIABLE_PRESENTATION_SLIDER) {
            $values = [$presentation['MIN'] ?? null, $presentation['MAX'] ?? null, $presentation['STEP_SIZE'] ?? null, $presentation['DIGITS'] ?? 0, $presentation['PREFIX'] ?? '', $presentation['SUFFIX'] ?? ''];
        } else {
            return null;
        }

        [$min, $max, $step, $digits, $prefix, $suffix] = $values;
        if (!is_numeric($min) || !is_numeric($max) || (float) $max <= (float) $min) {
            return null;
        }
        $digits = is_numeric($digits) ? max(0, min(6, (int) $digits)) : 0;
        // Ohne Schrittweite in Schritten der angezeigten Nachkommastellen
        $step = is_numeric($step) && (float) $step > 0 ? (float) $step : 10 ** -$digits;
        return [
            'min'    => (float) $min,
            'max'    => (float) $max,
            'step'   => $step,
            'digits' => $digits,
            'prefix' => is_string($prefix) ? $prefix : '',
            'suffix' => is_string($suffix) ? $suffix : ''
        ];
    }

    private function hexColor(mixed $color): string
    {
        return is_int($color) && $color >= 0 && $color <= 0xFFFFFF ? sprintf('#%06X', $color) : '';
    }
}