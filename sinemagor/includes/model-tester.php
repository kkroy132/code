<?php
defined('ABSPATH') || exit;

class Sinemagor_Model_Tester {

    public static function init(): void {
        add_action('wp_ajax_sg_test_model', [self::class, 'ajax_test']);
    }

    public static function ajax_test(): void {
        check_ajax_referer('sinemagor_nonce', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Permission denied.');

        $model   = sanitize_text_field($_POST['model']   ?? '');
        $api_key = sanitize_text_field($_POST['api_key'] ?? '');

        if (!$model || !$api_key) {
            wp_send_json_error('Model and API key are required.');
        }

        $url = 'https://openrouter.ai/api/v1/chat/completions';

        $prompt = 'Write 3 sentences reviewing the film "The Godfather" (1972). '
                . 'Be specific. No generic praise. Use plain language. '
                . 'Mention one concrete scene or detail.';

        $response = wp_remote_post($url, [
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
                'HTTP-Referer'  => home_url(),
                'X-Title'       => get_bloginfo('name'),
            ],
            'body' => wp_json_encode([
                'model'      => $model,
                'max_tokens' => 200,
                'temperature'=> 0.8,
                'messages'   => [
                    ['role' => 'user', 'content' => $prompt],
                ],
            ]),
        ]);

        if (is_wp_error($response)) {
            wp_send_json_error('Connection error: ' . $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200) {
            $err = $body['error']['message'] ?? ('HTTP ' . $code);
            wp_send_json_error('Provider error: ' . $err);
        }

        $content = $body['choices'][0]['message']['content'] ?? '';
        $usage   = $body['usage'] ?? [];
        $in_tok  = $usage['prompt_tokens']     ?? 0;
        $out_tok = $usage['completion_tokens']  ?? 0;
        $total   = $in_tok + $out_tok;

        // Rough cost estimate (per 1M tokens, varies by model)
        $cost_per_m = self::cost_per_million($model);
        $cost_est   = round(($total / 1_000_000) * $cost_per_m, 6);

        wp_send_json_success([
            'content' => trim($content),
            'model'   => $body['model'] ?? $model,
            'tokens'  => $total . ' (' . $in_tok . ' in + ' . $out_tok . ' out)',
            'cost'    => number_format($cost_est, 5),
        ]);
    }

    // Rough cost per 1M tokens (blended in+out, approximate)
    private static function cost_per_million(string $model): float {
        $map = [
            // DeepSeek
            'deepseek-v3'                       => 0.27,
            'deepseek/deepseek-v3.2'            => 0.30,
            'deepseek/deepseek-chat'            => 0.27,
            'deepseek-r1'                       => 0.55,
            'deepseek/deepseek-r1'              => 0.55,
            // Gemini
            'gemini-2.5-flash'                  => 0.15,
            'google/gemini-2.5-flash-preview'   => 0.15,
            'gemini-2.5-pro'                    => 1.25,
            'google/gemini-2.5-pro-preview'     => 1.25,
            // Claude
            'claude-3-5-haiku-20241022'         => 0.80,
            'anthropic/claude-3.5-haiku'        => 0.80,
            'claude-sonnet-4-5'                 => 3.00,
            'anthropic/claude-sonnet-4-5'       => 3.00,
            // GPT
            'gpt-4o-mini'                       => 0.15,
            'openai/gpt-4o-mini'                => 0.15,
            'gpt-4.1-nano'                      => 0.10,
            'openai/gpt-4.1-nano'               => 0.10,
            // Llama
            'llama-4-maverick'                  => 0.22,
            'meta-llama/llama-4-maverick'       => 0.22,
            'llama-3.3-70b-instruct'            => 0.12,
            'meta-llama/llama-3.3-70b-instruct' => 0.12,
        ];
        return $map[$model] ?? 0.50; // default fallback
    }
}
