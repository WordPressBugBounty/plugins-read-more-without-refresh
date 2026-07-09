<?php
/**
 * FAQPage structured data.
 *
 * v4 printed one <script type="application/ld+json"> FAQPage per accordion
 * item, which violates Google's guidelines (a page must expose a single
 * FAQPage entity) and used esc_js() escaping that produced invalid JSON.
 * v5 collects every question/answer pair during rendering and prints ONE
 * valid FAQPage in the footer using wp_json_encode().
 *
 * @package ReadMoreWithoutRefreshPro
 */

if (!defined('ABSPATH')) {
    exit;
}

class RMWR_Schema {

    /** @var array<int, array{question:string, answer:string}> */
    private static $faqs = array();

    public function __construct() {
        add_action('wp_footer', array($this, 'output'), 20);
    }

    /**
     * Queue one Q&A pair for the aggregated FAQPage block.
     *
     * @param string $question Plain-text question.
     * @param string $answer   Plain-text answer.
     */
    public static function add_faq($question, $answer) {
        $question = trim(wp_strip_all_tags((string) $question));
        $answer   = trim((string) $answer);

        if ('' === $question || '' === $answer) {
            return;
        }

        self::$faqs[] = array(
            'question' => $question,
            'answer'   => $answer,
        );
    }

    /**
     * Print a single FAQPage JSON-LD block for all collected pairs.
     */
    public function output() {
        if (empty(self::$faqs)) {
            return;
        }

        $entities = array();
        foreach (self::$faqs as $faq) {
            $entities[] = array(
                '@type'          => 'Question',
                'name'           => $faq['question'],
                'acceptedAnswer' => array(
                    '@type' => 'Answer',
                    'text'  => $faq['answer'],
                ),
            );
        }

        $schema = array(
            '@context'   => 'https://schema.org',
            '@type'      => 'FAQPage',
            'mainEntity' => $entities,
        );

        printf(
            '<script type="application/ld+json">%s</script>' . "\n",
            wp_json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        self::$faqs = array();
    }
}
