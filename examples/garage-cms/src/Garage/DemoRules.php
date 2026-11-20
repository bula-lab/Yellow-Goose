<?php
namespace Garage;

use ZoltaGaska\Http\HttpException;

/** Fictional catalog and prices in PLN grosze. All dimensions cross the PHP boundary as integer millimetres. */
final class DemoRules
{
    public const WIDTHS = [2500, 3000, 3500];
    public const DEPTHS = [4000, 5000, 6000];
    public const HEIGHTS = [2000, 2130, 2400];
    public const MATERIALS = [
        ['id' => 'graphite', 'title' => 'Graphite', 'color' => '#343d46'],
        ['id' => 'forest', 'title' => 'Forest green', 'color' => '#41634c'],
        ['id' => 'sand', 'title' => 'Sand', 'color' => '#c6ab84'],
    ];

    public static function initial(array $product): array
    {
        if (!$product['materials']) throw new HttpException(422, 'Select a finish for this garage.');
        return ['widthMm' => 3000, 'depthMm' => 5000, 'heightMm' => 2130, 'color' => $product['materials'][0]['color']];
    }

    public static function fields(array $product): array
    {
        $dimension = static fn (string $label, array $values) => ['type' => 'integer', 'label' => $label,
            'required' => true, 'choices' => $values, 'unit' => ['label' => 'cm', 'factor' => 10]];
        return ['widthMm' => $dimension('Width', self::WIDTHS), 'depthMm' => $dimension('Depth', self::DEPTHS),
            'heightMm' => $dimension('Height', self::HEIGHTS), 'color' => ['type' => 'string', 'label' => 'Color',
                'required' => true, 'choices' => array_column($product['materials'], 'color')]];
    }

    public static function quote(array $product, array $configuration): array
    {
        $expected = ['widthMm' => self::WIDTHS, 'depthMm' => self::DEPTHS, 'heightMm' => self::HEIGHTS,
            'color' => array_column($product['materials'], 'color')];
        $errors = [];
        foreach (array_diff_key($configuration, $expected) as $name => $_) $errors[$name][] = 'Unknown configuration field.';
        foreach ($expected as $name => $choices) {
            if (!in_array($configuration[$name] ?? null, $choices, true)) $errors[$name][] = 'Choose an offered variant.';
        }
        if (!$errors && $configuration['widthMm'] === 2500 && ($configuration['depthMm'] === 6000 || $configuration['heightMm'] === 2400)) {
            $errors['widthMm'][] = 'A 600 cm deep or 240 cm high garage requires a width of at least 300 cm.';
        }
        if ($errors) throw new HttpException(422, 'Invalid configuration.', $errors);
        $material = array_column($product['materials'], null, 'color')[$configuration['color']];
        $amount = 400000 + intdiv($configuration['widthMm'] - 2500, 500) * 42000
            + intdiv($configuration['depthMm'] - 4000, 1000) * 55000
            + [2000 => 0, 2130 => 12500, 2400 => 36000][$configuration['heightMm']]
            + (['graphite' => 0, 'forest' => 18000, 'sand' => 9000][$material['id']] ?? 24000);
        return ['productId' => $product['id'], 'revision' => $product['revision'], 'configuration' => $configuration,
            'materialId' => $material['id'], 'amountMinor' => $amount, 'currency' => 'PLN'];
    }

    public static function submittedQuote(array $product, array $input): array
    {
        if (!is_int($input['expectedRevision'] ?? null)) throw new HttpException(422, 'Product revision required.');
        if ($input['expectedRevision'] !== $product['revision']) throw new HttpException(409, 'The offer has changed. Reload it.');
        unset($input['expectedRevision']);
        return self::quote($product, $input);
    }
}
