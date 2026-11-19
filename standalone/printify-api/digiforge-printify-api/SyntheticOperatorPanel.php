<?php
declare(strict_types=1);

namespace DigiForgePrintifyApi;

if (!\defined('ABSPATH')) {
    exit;
}

/** Runs the fixed, in-memory fixture journey from the existing Tools page. */
final class SyntheticOperatorPanel
{
    private const CAPABILITY = 'OFFLINE_SYNTHETIC_JOURNEY';
    private const ACTION = 'digiforge_printify_synthetic_journey_v1';
    private const SUBMIT = 'digiforge_printify_run_synthetic';
    private const NONCE = 'digiforge_printify_synthetic_nonce';

    public static function render(): void
    {
        if (!\defined('DIGIFORGE_PRINTIFY_SYNTHETIC_JOURNEY') || DIGIFORGE_PRINTIFY_SYNTHETIC_JOURNEY !== true
            || !\function_exists('wp_get_environment_type') || \wp_get_environment_type() !== 'local'
            || !\function_exists('wp_create_nonce') || !\function_exists('wp_verify_nonce')
            || !in_array(self::CAPABILITY, Runtime::discover()['capabilities'], true)) {
            return;
        }

        echo '<h2>Synthetic Printify journey</h2>';
        echo '<p>Runs the fixed fixture from shop discovery through tracking and reconciliation with provider network access disabled.</p>';

        if (($_SERVER['REQUEST_METHOD'] ?? null) === 'POST' && isset($_POST[self::SUBMIT])) {
            $nonce = $_POST[self::NONCE] ?? null;
            if ($_POST[self::SUBMIT] === '1' && is_string($nonce)
                && preg_match('/^[a-f0-9]{10}$/D', $nonce) === 1
                && in_array(\wp_verify_nonce($nonce, self::ACTION), [1, 2], true)) {
                try {
                    $result = Runtime::runSyntheticJourney();
                } catch (\Throwable) {
                    $result = [];
                }
                self::renderResult($result);
            } else {
                echo '<p role="alert">The fixture run could not be verified. Reload this page and try again.</p>';
            }
        }

        echo '<form method="post">';
        echo '<input type="hidden" name="' . self::NONCE . '" value="' . \esc_attr(\wp_create_nonce(self::ACTION)) . '">';
        echo '<button type="submit" class="button button-primary" name="' . self::SUBMIT . '" value="1">Run synthetic journey</button>';
        echo '</form>';
    }

    private static function renderResult(array $result): void
    {
        $state = $result['state'] ?? null;
        $message = match ($state) {
            'SYNTHETIC_COMPLETE' => 'Synthetic journey passed.',
            'UNKNOWN' => 'Synthetic outcome is unknown. Reconciliation requires review.',
            default => 'Synthetic journey did not complete.',
        };

        echo '<p role="status">' . \esc_html($message) . '</p>';
        if ($state === 'SYNTHETIC_COMPLETE' && is_array($result['summary'] ?? null)) {
            self::renderSummary($result['summary']);
        }
        $steps = $result['steps'] ?? null;
        if (!is_array($steps)) {
            return;
        }

        echo '<table class="widefat striped"><caption>Synthetic operation results</caption><thead><tr>';
        echo '<th scope="col">Operation</th><th scope="col">Fixture HTTP status</th></tr></thead><tbody>';
        foreach (array_slice($steps, 0, 32) as $step) {
            if (!is_array($step)) {
                continue;
            }
            $operation = $step['operation'] ?? null;
            $status = $step['status'] ?? null;
            if (!is_string($operation) || preg_match('/^[A-Z][A-Z_0-9]{0,63}$/D', $operation) !== 1
                || !is_int($status) || $status < 100 || $status > 599) {
                continue;
            }
            echo '<tr><th scope="row">' . \esc_html($operation) . '</th><td>'
                . \esc_html((string)$status) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    private static function renderSummary(array $summary): void
    {
        $values = [];
        foreach (['product_id'=>'Product ID', 'order_id'=>'Order ID', 'order_status'=>'Order status'] as $key => $label) {
            $value = $summary[$key] ?? null;
            if (is_string($value) && preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/D', $value) === 1) {
                $values[$label] = $value;
            }
        }
        $quote = $summary['shipping_standard_minor'] ?? null;
        if (is_int($quote) && $quote >= 0 && $quote <= 1_000_000_000) {
            $values['Standard shipping quote (minor units, currency unknown)'] = (string)$quote;
        }
        $shipments = $summary['shipment_count'] ?? null;
        if (is_int($shipments) && $shipments >= 0 && $shipments <= 100) {
            $values['Shipment count'] = (string)$shipments;
        }
        if ($values === []) {
            return;
        }

        echo '<h3>Synthetic product and order summary</h3><dl>';
        foreach ($values as $label => $value) {
            echo '<dt>' . \esc_html($label) . '</dt><dd>' . \esc_html($value) . '</dd>';
        }
        echo '</dl>';
    }
}
