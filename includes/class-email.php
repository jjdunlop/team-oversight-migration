<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The club's one email look (1.72.0). Every email the plugin sends (the
 * trial applicant emails and the overdue fee reminder) is built here, so
 * members always see the same layout: the club logo and name, the program
 * the email is about above its heading, the message, and the same footer.
 *
 * Admins still write the words, as plain text on VVL Oversight → Emails
 * ("email text"): a blank line starts a new paragraph, {placeholders} are
 * filled in, web addresses become links, a paragraph starting with ">" is
 * a highlighted note, and a few tokens on a line of their own are replaced
 * by blocks the plugin draws ({fees}, {details}, {button} or
 * {button:Your own words}). The layout itself isn't editable, so emails
 * can't drift apart.
 *
 * Everything is tables and inline styles, which is what email programs
 * (Gmail, Outlook, Apple Mail) reliably show; the logo travels inside the
 * email, so it shows without "download images". Each email also carries a
 * plain-text version of the same words.
 */
class TeamOversight_Email {

    // Club palette (the site's colours) and the fee bar's colours, which
    // match the bar members see on the Player Checklist. The "due today"
    // line is club blue rather than the page's near-black: dark modes turn
    // a black line on a grey track invisible.
    const RED = '#C8102E';
    const BLUE = '#1F4E9C';
    const NAVY = '#0F2A52';
    const INK = '#16202E';
    const MUTED = '#5B6472';
    const LIGHT = '#F3F5F8';
    const LINE = '#E3E7EE';
    const BAR_ONTRACK = '#46b450';
    const BAR_BEHIND = '#dc3232';
    const BAR_TRACK = '#e8e8e8';
    const BAR_MARKER = '#1F4E9C';
    const FONT = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";

    const CLUB_NAME = 'Melbourne University Renegades Volleyball Club';

    /** The logo inside the email (Content-ID), see send(). */
    const LOGO_CID = 'murvc-logo';

    /**
     * Sent on every email built here, so anything that later wraps other
     * mail in the club look can leave these alone.
     */
    const MARKER_HEADER = 'X-MURVC-Email: club-template';

    /**
     * A block token on a line of its own: {fees}, {details}, {button} or
     * {button:Label}. Spaces inside the braces and any case are allowed.
     */
    const TOKEN_PATTERN = '/^\{\s*(fees|details|button)\s*(?::\s*([^{}\r\n]{1,60}?)\s*)?\}$/i';

    // ------------------------------------------------------------------
    // Building an email
    // ------------------------------------------------------------------

    /**
     * Build one email. Returns array('html' => ..., 'text' => ...).
     *
     * $args:
     *  program   the program it's about, shown above the heading ("Club fees · 2026")
     *  heading   the heading (email text: placeholders are filled in)
     *  body      the admin's email text
     *  vars      placeholder => plain value ('{first_name}' => 'Alex')
     *  blocks    'fees' / 'details' => array('html' => ..., 'text' => ...)
     *  button    array('url' => ..., 'label' => default words) or null
     *  preheader the line inboxes show after the subject (email text)
     *  why       why they're getting it, for the footer
     */
    public static function render($args) {
        $args = wp_parse_args($args, array(
            'program' => '',
            'heading' => '',
            'body' => '',
            'vars' => array(),
            'blocks' => array(),
            'button' => null,
            'preheader' => '',
            'why' => '',
        ));
        $vars = self::plain_vars($args['vars']);
        $heading = self::fill($args['heading'], $vars);
        $preheader = self::fill($args['preheader'], $vars);

        $html_parts = array();
        $text_parts = array();
        foreach (self::paragraphs($args['body']) as $paragraph) {
            foreach (self::split_tokens($paragraph) as $piece) {
                list($html, $text) = self::render_piece($piece, $vars, $args['blocks'], $args['button']);
                if ($html !== '') {
                    $html_parts[] = $html;
                    $text_parts[] = $text;
                }
            }
        }

        return array(
            'html' => self::layout($args['program'], $heading, implode("\n", $html_parts), $preheader, $args['why']),
            'text' => self::text_layout($args['program'], $heading, $text_parts, $args['why']),
        );
    }

    /**
     * What to call someone: their profile's first name, else the first
     * word of their name. One rule for every email.
     */
    public static function first_name($user_id, $name) {
        $first = $user_id ? trim((string) get_user_meta(intval($user_id), 'first_name', true)) : '';
        if ($first === '') {
            $first = trim((string) strtok((string) $name, ' '));
        }
        return $first !== '' ? $first : (string) $name;
    }

    /** Fill placeholders in plain text (a subject, a heading): no HTML, one line. */
    public static function fill($text, $vars) {
        $filled = strtr(self::decode($text), self::plain_vars($vars));
        return trim(preg_replace('/\s+/u', ' ', $filled));
    }

    /** Text with its entities decoded, the same way on every PHP version. */
    public static function decode($text) {
        return html_entity_decode((string) $text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Placeholder values as plain text. Display names are stored with their
     * entities encoded ("&amp;"), so every value is decoded once here and
     * escaped once on the way into the HTML. Runs of spaces and line
     * breaks become one space, so a value can never start a new paragraph,
     * a note or a block.
     */
    private static function plain_vars($vars) {
        $plain = array();
        foreach ((array) $vars as $key => $value) {
            $plain[$key] = trim(preg_replace('/\s+/u', ' ', self::decode($value)));
        }
        return $plain;
    }

    /** Email text split into paragraphs at blank lines (any line endings). */
    public static function paragraphs($text) {
        $text = trim(str_replace(array("\r\n", "\r"), "\n", (string) $text));
        if ($text === '') {
            return array();
        }
        $out = array();
        foreach (preg_split('/\n[ \t]*\n/', $text) as $paragraph) {
            $paragraph = rtrim(trim($paragraph, "\n"));
            if (trim($paragraph) !== '') {
                $out[] = $paragraph;
            }
        }
        return $out;
    }

    /**
     * A paragraph cut around any block token on a line of its own, so
     * "Pay online:\n{button}" is the words, then the button.
     */
    private static function split_tokens($paragraph) {
        $pieces = array();
        $words = array();
        foreach (explode("\n", $paragraph) as $line) {
            if (preg_match(self::TOKEN_PATTERN, trim($line))) {
                if ($words) {
                    $pieces[] = implode("\n", $words);
                    $words = array();
                }
                $pieces[] = trim($line);
            } else {
                $words[] = $line;
            }
        }
        if ($words && trim(implode('', $words)) !== '') {
            $pieces[] = implode("\n", $words);
        }
        return $pieces;
    }

    /** One piece as array(html, text): a block, a note or plain words. */
    private static function render_piece($piece, $vars, $blocks, $button) {
        $trimmed = trim($piece);

        if (preg_match(self::TOKEN_PATTERN, $trimmed, $m)) {
            $token = strtolower($m[1]);
            if ($token === 'button' && $button && !empty($button['url'])) {
                $label = isset($m[2]) && trim($m[2]) !== '' ? self::fill($m[2], $vars) : $button['label'];
                return array(self::button($label, $button['url']), $label . ': ' . $button['url']);
            }
            if ($token !== 'button' && isset($blocks[$token])) {
                return array($blocks[$token]['html'], $blocks[$token]['text']);
            }
            // A block this email has nothing for: left out, never shown as code.
            return array('', '');
        }

        if (strpos($trimmed, '>') === 0) {
            $lines = array();
            foreach (explode("\n", $trimmed) as $line) {
                $lines[] = preg_replace('/^\s*>\s?/', '', $line);
            }
            $words = implode("\n", $lines);
            return array(self::note(self::words_html($words, $vars)), self::words_text($words, $vars));
        }

        return array(self::paragraph(self::words_html($piece, $vars)), self::words_text($piece, $vars));
    }

    /**
     * Admin words as HTML. Web addresses are found in the admin's own text
     * (never in a filled-in value, so a member's name can't become a
     * link); {link} used inside a sentence is a link too. Everything else
     * has its placeholders filled and is escaped; line breaks come last.
     */
    private static function words_html($words, $vars) {
        $html = '';
        foreach (preg_split('~(https?://[^\s<>"\'{}]+|\{link\})~i', $words, -1, PREG_SPLIT_DELIM_CAPTURE) as $i => $segment) {
            if ($i % 2 === 0) {
                $html .= esc_html(strtr(self::decode($segment), $vars));
                continue;
            }
            if (strtolower($segment) === '{link}') {
                $url = isset($vars['{link}']) ? $vars['{link}'] : '';
                $html .= $url !== '' ? self::link($url) : '';
                continue;
            }
            // Punctuation ending a sentence stays outside the link.
            $tail = '';
            if (preg_match('/[.,;:!?)\]]+$/', $segment, $t)) {
                $tail = $t[0];
                $segment = substr($segment, 0, -strlen($tail));
            }
            $html .= self::link(self::decode($segment)) . esc_html($tail);
        }
        return nl2br($html, false);
    }

    /** The same words for the plain-text version. */
    private static function words_text($words, $vars) {
        return strtr(self::decode($words), $vars);
    }

    /** A link showing its own address (long ones may break across lines on phones). */
    private static function link($url) {
        $href = esc_url($url);
        if ($href === '') {
            return esc_html($url);
        }
        return '<a href="' . $href . '" style="color:' . self::BLUE . ';text-decoration:underline;word-break:break-all;">' . esc_html($url) . '</a>';
    }

    // ------------------------------------------------------------------
    // Sending
    // ------------------------------------------------------------------

    /**
     * Send a built email through wp_mail(), so other plugins' mail guards
     * (the account merge's) and the site's mailer still apply. The HTML
     * content type is set on this message only, never through the global
     * wp_mail_content_type filter, which would turn every other plugin's
     * plain-text mail into one run-on paragraph. A one-shot phpmailer_init
     * hook, added just for this call and removed whatever happens, adds the
     * plain-text version and the logo to this message.
     */
    public static function send($to, $subject, $email, $headers = array()) {
        $headers = array_values(array_filter((array) $headers, function ($header) {
            return stripos((string) $header, 'Content-Type:') !== 0;
        }));
        $headers[] = 'Content-Type: text/html; charset=UTF-8';
        $headers[] = self::MARKER_HEADER;
        $subject = trim(preg_replace('/\s+/u', ' ', (string) $subject));

        $html = (string) $email['html'];
        $text = isset($email['text']) ? (string) $email['text'] : '';
        $logo = TEAM_OVERSIGHT_PLUGIN_DIR . 'assets/email-logo.png';
        $priority = PHP_INT_MAX;
        $init = function ($phpmailer) use ($text, $logo, $priority, &$init) {
            // This message only: off again before anything else can send.
            remove_action('phpmailer_init', $init, $priority);
            if ($phpmailer->ContentType !== 'text/html') {
                // Something forced plain text: send the plain version, not raw HTML.
                $phpmailer->Body = $text;
                $phpmailer->AltBody = '';
                return;
            }
            $phpmailer->AltBody = $text;
            if (strpos($phpmailer->Body, 'cid:' . TeamOversight_Email::LOGO_CID) !== false && is_readable($logo)) {
                $phpmailer->addEmbeddedImage($logo, TeamOversight_Email::LOGO_CID, 'renegades.png', 'base64', 'image/png');
            }
        };
        add_action('phpmailer_init', $init, $priority);
        try {
            $sent = wp_mail($to, $subject, $html, $headers);
        } finally {
            remove_action('phpmailer_init', $init, $priority);
        }
        return (bool) $sent;
    }

    // ------------------------------------------------------------------
    // The layout
    // ------------------------------------------------------------------

    /**
     * The logo's web address, for previews on admin pages (an email shows
     * the copy it carries). A small PNG: Gmail doesn't show SVG.
     */
    public static function logo_url() {
        return (string) apply_filters('team_oversight_email_logo_url', TEAM_OVERSIGHT_PLUGIN_URL . 'assets/email-logo.png');
    }

    private static function layout($program, $heading, $body_html, $preheader, $why) {
        $f = self::FONT;
        $home = esc_url(home_url('/'));
        $site = esc_html(preg_replace('~^https?://~', '', untrailingslashit(home_url())));
        $title = esc_html($heading);

        $preheader_html = '';
        if ($preheader !== '') {
            // Hidden text inboxes show after the subject, padded so the
            // body's first words don't follow it.
            $preheader_html = '<div style="display:none;font-size:1px;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;mso-hide:all;">'
                . esc_html($preheader) . str_repeat('&#8199;&#847; ', 40) . '</div>';
        }

        $program_html = $program !== ''
            ? '<p style="margin:0 0 6px;font-family:' . $f . ';font-size:12px;line-height:1.4;font-weight:700;letter-spacing:1.5px;text-transform:uppercase;color:' . self::RED . ';">' . esc_html($program) . '</p>'
            : '';

        // A different hidden string in every email, so Gmail never folds
        // the body away as "repeated" in a thread of same-subject emails.
        $unique = '<span style="display:none;mso-hide:all;font-size:0;line-height:0;max-height:0;overflow:hidden;opacity:0;color:transparent;">' . esc_html(wp_generate_uuid4()) . '</span>';

        return '<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="x-apple-disable-message-reformatting">
<meta name="format-detection" content="telephone=no, date=no, address=no, email=no">
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
<title>' . $title . '</title>
<!--[if mso]><noscript><xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml></noscript><![endif]-->
<style>
  :root { color-scheme: light; supported-color-schemes: light; }
  a[x-apple-data-detectors] { color: inherit !important; text-decoration: none !important; font-size: inherit !important; font-family: inherit !important; font-weight: inherit !important; line-height: inherit !important; }
  @media (max-width: 620px) {
    .murvc-pad { padding-left: 20px !important; padding-right: 20px !important; }
    .murvc-h1 { font-size: 22px !important; }
  }
</style>
</head>
<body style="margin:0;padding:0;width:100%;background-color:' . self::LIGHT . ';-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%;">
' . $preheader_html . '
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="' . self::LIGHT . '" style="background-color:' . self::LIGHT . ';">
<tr><td align="center" style="padding:24px 12px;">
<!--[if mso]><table role="presentation" width="600" align="center" cellpadding="0" cellspacing="0" border="0"><tr><td><![endif]-->
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;margin:0 auto;">
<tr><td bgcolor="#ffffff" style="background-color:#ffffff;border-top:4px solid ' . self::RED . ';border-radius:10px;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
  <tr><td class="murvc-pad" style="padding:22px 32px 18px;border-bottom:1px solid ' . self::LINE . ';">
    <table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>
      <td width="52" style="padding-right:12px;vertical-align:middle;"><a href="' . $home . '"><img src="cid:' . self::LOGO_CID . '" width="52" height="52" alt="Renegades" style="display:block;width:52px;height:52px;border:0;border-radius:8px;outline:none;text-decoration:none;"></a></td>
      <td style="vertical-align:middle;font-family:' . $f . ';">
        <div style="font-size:12px;line-height:1.4;letter-spacing:0.4px;color:' . self::MUTED . ';">Melbourne University</div>
        <div style="font-size:18px;line-height:1.2;font-weight:800;color:' . self::NAVY . ';">Renegades Volleyball Club</div>
      </td>
    </tr></table>
  </td></tr>
  <tr><td class="murvc-pad" style="padding:28px 32px 12px;">
    ' . $program_html . '
    <h1 class="murvc-h1" style="margin:0 0 18px;font-family:' . $f . ';font-size:25px;line-height:1.25;font-weight:800;color:' . self::NAVY . ';">' . $title . '</h1>
' . $body_html . '
  </td></tr>
  </table>
</td></tr>
<tr><td class="murvc-pad" style="padding:20px 32px;text-align:center;font-family:' . $f . ';font-size:12px;line-height:1.6;color:' . self::MUTED . ';">
  Questions? Just reply to this email.<br>
  <a href="' . $home . '" style="color:' . self::MUTED . ';font-weight:700;text-decoration:none;">' . esc_html(self::CLUB_NAME) . '</a> &middot; <a href="' . $home . '" style="color:' . self::MUTED . ';text-decoration:underline;">' . $site . '</a>'
            . ($why !== '' ? '<br>' . esc_html($why) : '') . $unique . '
</td></tr>
</table>
<!--[if mso]></td></tr></table><![endif]-->
</td></tr>
</table>
</body>
</html>';
    }

    /** The plain-text version: the same words, blocks as short text. */
    private static function text_layout($program, $heading, $parts, $why) {
        $lines = array();
        if ($program !== '') {
            $lines[] = function_exists('mb_strtoupper') ? mb_strtoupper($program, 'UTF-8') : strtoupper($program);
        }
        $lines[] = $heading;
        return implode("\n", $lines) . "\n\n" . implode("\n\n", $parts)
            . "\n\n--\nQuestions? Just reply to this email.\n" . self::CLUB_NAME . "\n" . home_url('/')
            . ($why !== '' ? "\n" . $why : '');
    }

    // ------------------------------------------------------------------
    // Building blocks (shared by every email, so they look the same)
    // ------------------------------------------------------------------

    /** A paragraph of (already escaped) HTML. */
    public static function paragraph($html, $size = 16, $color = null, $margin = '0 0 16px') {
        return '<p style="margin:' . $margin . ';font-family:' . self::FONT . ';font-size:' . intval($size) . 'px;line-height:1.55;color:' . ($color ? $color : self::INK) . ';word-break:break-word;">' . $html . '</p>';
    }

    /** A highlighted note (pale yellow, with an amber edge). */
    public static function note($html) {
        return self::box(self::paragraph($html, 15, self::INK, '0'), '#FFF8E6', false, '#f0b429');
    }

    /**
     * A rounded box around some HTML. The space around it is padding on
     * a wrapping cell, which every email program keeps (Outlook drops
     * margins on tables).
     */
    public static function box($inner, $background = '#ffffff', $border = true, $accent = null) {
        $style = $border ? 'border:1px solid ' . self::LINE . ';' : '';
        if ($accent) {
            $style .= 'border-left:4px solid ' . $accent . ';';
        }
        return self::spaced('<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
            . '<td bgcolor="' . $background . '" style="background-color:' . $background . ';' . $style . 'border-radius:8px;padding:16px 20px;">' . $inner . '</td></tr></table>');
    }

    /** A block with the standard space above and below it. */
    private static function spaced($html) {
        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td style="padding:4px 0 20px;">' . $html . '</td></tr></table>';
    }

    /**
     * The button: a table cell (Outlook pads the cell; everything else pads
     * the link, so the whole button is clickable).
     */
    public static function button($label, $url) {
        $href = esc_url($url);
        if ($href === '') {
            return '';
        }
        return self::spaced('<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>'
            . '<td bgcolor="' . self::BLUE . '" style="background-color:' . self::BLUE . ';border-radius:6px;mso-padding-alt:13px 24px;">'
            . '<a href="' . $href . '" style="display:inline-block;padding:13px 24px;font-family:' . self::FONT . ';font-size:16px;line-height:1.2;font-weight:700;color:#ffffff;text-decoration:none;border-radius:6px;">'
            . esc_html($label) . '</a></td></tr></table>');
    }

    /** A small rounded label: 'bad' red, 'good' green, 'neutral' grey. */
    public static function pill($text, $kind) {
        $colours = array(
            'bad' => array('#fce8e8', '#a00000'),
            'good' => array('#e8f5ea', '#1e6b2a'),
            'neutral' => array('#eef0f3', self::MUTED),
        );
        $c = isset($colours[$kind]) ? $colours[$kind] : $colours['neutral'];
        return '<span style="display:inline-block;padding:3px 10px;border-radius:999px;background-color:' . $c[0] . ';color:' . $c[1] . ';'
            . 'font-family:' . self::FONT . ';font-size:12px;line-height:1.4;font-weight:700;white-space:nowrap;">' . esc_html($text) . '</span>';
    }

    /**
     * Label / value rows, values on the right (amounts). $rows: array of
     * array(label, value text, style) with style '', 'total', 'bad' or 'good'.
     */
    public static function amount_rows($rows) {
        $html = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">';
        foreach ($rows as $row) {
            list($label, $value, $style) = array_pad($row, 3, '');
            $top = $style === 'total' ? 'border-top:1px solid ' . self::LINE . ';' : '';
            $weight = $style === '' ? '400' : '700';
            $colour = $style === 'bad' ? '#a00000' : ($style === 'good' ? '#155724' : self::INK);
            $label_colour = $style === '' ? self::MUTED : $colour;
            $cell = 'padding:6px 0;font-family:' . self::FONT . ';font-size:15px;line-height:1.4;font-weight:' . $weight . ';vertical-align:top;' . $top;
            $html .= '<tr><td style="' . $cell . 'padding-right:12px;color:' . $label_colour . ';">' . esc_html($label) . '</td>'
                . '<td style="' . $cell . 'color:' . $colour . ';text-align:right;white-space:nowrap;">' . esc_html($value) . '</td></tr>';
        }
        return $html . '</table>';
    }

    /**
     * A light box of facts, values on the left (names, lists), with an
     * optional big headline figure. $rows: array(label, value text).
     */
    public static function details($rows, $headline_label = '', $headline = '') {
        $inner = '';
        if ($headline !== '') {
            $inner .= '<p style="margin:0 0 2px;font-family:' . self::FONT . ';font-size:12px;line-height:1.4;font-weight:700;letter-spacing:1.2px;text-transform:uppercase;color:' . self::MUTED . ';">' . esc_html($headline_label) . '</p>'
                . '<p style="margin:0 0 12px;font-family:' . self::FONT . ';font-size:34px;line-height:1.1;font-weight:800;color:' . self::NAVY . ';">' . esc_html($headline) . '</p>';
        }
        if ($rows) {
            $inner .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">';
            foreach ($rows as $row) {
                $cell = 'padding:5px 0;font-family:' . self::FONT . ';font-size:15px;line-height:1.45;vertical-align:top;';
                $inner .= '<tr><td width="34%" style="' . $cell . 'padding-right:12px;color:' . self::MUTED . ';">' . esc_html($row[0]) . '</td>'
                    . '<td style="' . $cell . 'color:' . self::INK . ';word-break:break-word;">' . esc_html($row[1]) . '</td></tr>';
            }
            $inner .= '</table>';
        }
        return self::box($inner, self::LIGHT, false);
    }

    /**
     * The fee progress bar members see on the Player Checklist, drawn so
     * email programs show it: one 10px row of percentage-width cells, the
     * "due today" line a 3px border where the cell at that point starts.
     * (A column of its own gets squeezed to nothing when the percentages
     * fill the row, and rows thinner than their text grow in apps with a
     * minimum text size, so the line can't stick out as it does on the page.)
     * $paid_pct / $due_pct 0-100; $behind picks the red fill.
     */
    public static function progress_bar($paid_pct, $due_pct, $behind) {
        $fill = $behind ? self::BAR_BEHIND : self::BAR_ONTRACK;
        $paid = max(0, min(100, intval($paid_pct)));
        $due = max(0, min(100, intval($due_pct)));
        // array(colour, from %, to %)
        if ($paid >= $due) {
            $cells = array(array($fill, 0, $due), array($fill, $due, $paid), array(self::BAR_TRACK, $paid, 100));
        } else {
            $cells = array(array($fill, 0, $paid), array(self::BAR_TRACK, $paid, $due), array(self::BAR_TRACK, $due, 100));
        }
        $cells = array_values(array_filter($cells, function ($cell) {
            return $cell[2] > $cell[1];
        }));

        $line = '3px solid ' . self::BAR_MARKER;
        $row = '<tr>';
        $last = count($cells) - 1;
        foreach ($cells as $i => $cell) {
            $width = $cell[2] - $cell[1];
            $border = '';
            if ($cell[1] === $due) {
                $border = 'border-left:' . $line . ';';
            } elseif ($i === $last && $due === 100) {
                $border = 'border-right:' . $line . ';';
            }
            $row .= '<td width="' . $width . '%" height="10" bgcolor="' . $cell[0] . '" style="width:' . $width . '%;height:10px;font-size:1px;line-height:1px;mso-line-height-rule:exactly;background-color:' . $cell[0] . ';' . $border . '">&nbsp;</td>';
        }
        $row .= '</tr>';
        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;">' . $row . '</table>';
    }

    /**
     * Fee cards, the email version of the checklist's fees step. $cards
     * from TeamOversight_Payments::fee_cards() (or sample cards for
     * previews); $totals adds a line across all seasons when there are
     * several. The bar's explanation and the "as at" date go in the last
     * card. Returns array('html' => ..., 'text' => ...).
     */
    public static function fee_block($cards, $as_at, $totals = null) {
        $html = '';
        $text = array();
        if ($totals && count($cards) > 1) {
            $html .= self::paragraph('Across all your seasons: <strong>' . esc_html(self::money($totals['overdue'])) . ' overdue now</strong>, ' . esc_html(self::money($totals['outstanding'])) . ' remaining.', 15);
            $text[] = 'Across all your seasons: ' . self::money($totals['overdue']) . ' overdue now, ' . self::money($totals['outstanding']) . ' remaining.';
        }
        $has_bar = false;
        foreach ($cards as $card) {
            if (!empty($card['bar'])) {
                $has_bar = true;
            }
        }
        $small = 'margin:0;font-family:' . self::FONT . ';font-size:12px;line-height:1.5;color:#666666;';
        $last = count($cards) - 1;
        foreach (array_values($cards) as $i => $card) {
            $inner = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 6px;"><tr>'
                . '<td style="font-family:' . self::FONT . ';font-size:17px;line-height:1.3;font-weight:700;color:' . self::NAVY . ';">' . esc_html($card['title']) . '</td>'
                . '<td style="text-align:right;">' . ($card['pill'] ? self::pill($card['pill'][0], $card['pill'][1]) : '') . '</td></tr></table>';
            $card_text = array($card['title'] . ($card['pill'] ? ' (' . $card['pill'][0] . ')' : ''));
            if (!empty($card['schedule'])) {
                $inner .= self::paragraph(esc_html($card['schedule']), 13, self::MUTED, '0 0 8px');
                $card_text[] = $card['schedule'];
            }
            $inner .= self::amount_rows($card['rows']);
            foreach ($card['rows'] as $row) {
                $card_text[] = '  ' . $row[0] . ': ' . $row[1];
            }
            if (!empty($card['bar'])) {
                $bar = $card['bar'];
                $inner .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td style="padding:14px 0 6px;">'
                    . self::progress_bar($bar['paid_pct'], $bar['due_pct'], $bar['behind']) . '</td></tr></table>'
                    . '<p style="' . $small . '">' . esc_html($bar['caption']) . '</p>';
                $card_text[] = '  ' . $bar['caption'];
            }
            if ($i === $last) {
                $foot = ($has_bar ? 'The coloured bar is what you\'ve paid; the thin blue line is where the schedule expects you to be today. ' : '')
                    . 'Figures as at ' . $as_at . '.';
                $inner .= '<p style="' . $small . 'padding-top:8px;">' . esc_html($foot) . '</p>';
            }
            $html .= self::box($inner);
            $text[] = implode("\n", $card_text);
        }
        $text[] = 'Figures as at ' . $as_at . '.';
        return array('html' => $html, 'text' => implode("\n\n", $text));
    }

    public static function money($amount) {
        return '$' . number_format((float) $amount, 2);
    }

    // ------------------------------------------------------------------
    // Admin helpers
    // ------------------------------------------------------------------

    /**
     * An email shown on an admin page, in a frame so the admin page's own
     * styles can't change how it looks. The frame runs no scripts (the
     * sandbox has no allow-scripts) and its contents are double-encoded,
     * so escaped text in the email stays text. The page sizes it to fit
     * once it can be seen (a closed Preview has no size yet).
     */
    public static function preview_frame($html, $height = 560) {
        $html = str_replace('cid:' . self::LOGO_CID, esc_url(self::logo_url()), $html);
        $html = str_replace('<head>', '<head><base target="_blank">', $html);
        $id = 'murvc-email-preview-' . wp_rand(1000, 999999);
        return '<iframe id="' . esc_attr($id) . '" sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox" title="Email preview" style="display:block;width:100%;max-width:640px;height:' . intval($height) . 'px;border:1px solid #dcdcde;border-radius:4px;background:' . self::LIGHT . ';"'
            . ' srcdoc="' . htmlspecialchars($html, ENT_QUOTES, 'UTF-8', true) . '"></iframe>'
            . '<script>(function(){var f=document.getElementById(' . wp_json_encode($id) . ');if(!f){return;}'
            . 'function fit(){if(!f.getClientRects().length){return;}try{var h=f.contentDocument.documentElement.scrollHeight;if(h>100){f.style.height=(h+4)+"px";}}catch(e){}}'
            . 'f.addEventListener("load",fit);var d=f.closest("details");if(d){d.addEventListener("toggle",function(){if(d.open){fit();}});}fit();})();</script>';
    }

    /** The help text admins see beside every email body. */
    public static function format_help() {
        return 'Write plain text. A blank line starts a new paragraph, and web addresses become links. '
            . 'Start a paragraph with <code>&gt;</code> to highlight it. '
            . 'Put <code>{button}</code> on a line of its own for the button, or <code>{button:Your own words}</code> to change what it says. '
            . 'The club logo, heading, layout and footer are added for you, so there\'s no need to sign off.';
    }

    /**
     * Things in some email text that won't come out as the admin probably
     * means, in plain words: placeholders the email can't fill (they'd
     * show as typed), block tokens inside a sentence (they only work on a
     * line of their own), a missing required block. Empty when all's well.
     */
    public static function check_text($text, $placeholders, $blocks, $required = array()) {
        $warnings = array();
        $lines = explode("\n", str_replace(array("\r\n", "\r"), "\n", (string) $text));
        $own_line = array();
        foreach ($lines as $line) {
            if (preg_match(self::TOKEN_PATTERN, trim($line), $m)) {
                $own_line[strtolower($m[1])] = true;
            }
        }
        preg_match_all('/\{\s*([a-z_]+)\s*(?::[^{}\r\n]*)?\}/i', (string) $text, $found);
        foreach (array_unique(array_map('strtolower', $found[1])) as $name) {
            if (in_array('{' . $name . '}', $placeholders, true)) {
                continue;
            }
            if (in_array($name, $blocks, true)) {
                if (empty($own_line[$name])) {
                    $warnings[] = '{' . $name . '} only works on a line of its own, so as it is it would show as typed.';
                }
                continue;
            }
            $warnings[] = '{' . $name . '} isn\'t something this email can fill in, so it would show as typed.';
        }
        foreach ($required as $name => $why) {
            if (empty($own_line[$name])) {
                $warnings[] = 'There\'s no {' . $name . '} line, so ' . $why;
            }
        }
        return $warnings;
    }

    /** Line endings and trailing spaces differ between a textarea round-trip and PHP source. */
    public static function normalise($text) {
        $lines = explode("\n", str_replace(array("\r\n", "\r"), "\n", (string) $text));
        return trim(implode("\n", array_map('rtrim', $lines)));
    }

    // ------------------------------------------------------------------
    // The one-off move of saved wording to this format (1.72.0)
    // ------------------------------------------------------------------

    /**
     * Set once the 1.72 wording options exist. The wording itself lives in
     * new options (TeamOversight_Payments::REMINDER_TEXT_OPTION,
     * TeamOversight_Trial_Emails::TEXT_OPTION); the 1.71 options are left
     * exactly as they were, so a site put back on 1.71 still sends its old
     * wording, never these tokens.
     */
    const FORMAT_OPTION = 'team_oversight_email_format';

    /** Posted with the Emails page's forms, so a page opened before 1.72 is caught. */
    const FORM_FORMAT = '2';

    /**
     * Before 1.72.0 the emails were plain text, laid out with spaces and
     * signed off by hand. On the first load after the update the saved
     * wording is copied into the new options in this format: wording the
     * admin changed is kept word for word, and only the layout bits are
     * swapped for blocks (the spaced-out figures for {fees} / {details},
     * a link on its own line for {button}, the sign-off dropped because
     * the footer has the club's name). Wording never changed becomes
     * "use the default".
     */
    public static function maybe_upgrade() {
        if (intval(get_option(self::FORMAT_OPTION, 1)) >= 2) {
            return;
        }
        global $wpdb;
        // One request at a time: '0' means another request is doing it.
        // A database that can't do named locks ('' / null) goes ahead.
        $lock = $wpdb->get_var("SELECT GET_LOCK('murvc_email_format', 5)");
        if ($lock === '0') {
            return;
        }
        try {
            if (intval(self::raw_option(self::FORMAT_OPTION)) < 2) {
                self::upgrade_texts();
                update_option(self::FORMAT_OPTION, 2);
            }
        } finally {
            if ($lock === '1') {
                $wpdb->query("SELECT RELEASE_LOCK('murvc_email_format')");
            }
        }
    }

    /** An option straight from the database (not this request's cache), or null. */
    private static function raw_option($name) {
        global $wpdb;
        $value = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name));
        return $value === null ? null : maybe_unserialize($value);
    }

    private static function upgrade_texts() {
        $legacy = self::legacy_defaults();

        if (self::raw_option(TeamOversight_Payments::REMINDER_TEXT_OPTION) === null) {
            $subject = self::raw_option(TeamOversight_Payments::EMAIL_SUBJECT_OPTION);
            $body = self::raw_option(TeamOversight_Payments::EMAIL_BODY_OPTION);
            $subject = is_string($subject) ? trim($subject) : '';
            add_option(TeamOversight_Payments::REMINDER_TEXT_OPTION, array(
                'subject' => ($subject === '' || $subject === $legacy['reminder_subject'] || $subject === TeamOversight_Payments::get_default_reminder_subject()) ? '' : $subject,
                'heading' => '',
                'body' => is_string($body) ? self::upgrade_text($body, $legacy['reminder_body'], 'fees', TeamOversight_Payments::get_default_reminder_body()) : '',
            ));
        }

        if (self::raw_option(TeamOversight_Trial_Emails::TEXT_OPTION) === null) {
            $old = self::raw_option(TeamOversight_Trial_Emails::OPTION);
            $old = is_array($old) ? $old : array();
            $texts = array();
            foreach (TeamOversight_Trial_Emails::get_events() as $key => $event) {
                $mine = isset($old[$key]) && is_array($old[$key]) ? $old[$key] : array();
                $subject = isset($mine['subject']) ? trim((string) $mine['subject']) : '';
                $body = isset($mine['body']) ? (string) $mine['body'] : '';
                $texts[$key] = array(
                    // Trial subjects are the same in both formats.
                    'subject' => $subject === $event['subject'] ? '' : $subject,
                    'heading' => '',
                    'body' => self::upgrade_text($body, $legacy['trial'][$key], 'details', $event['body']),
                );
            }
            add_option(TeamOversight_Trial_Emails::TEXT_OPTION, $texts);
        }
    }

    /**
     * One saved text in this format: '' when it's blank, the old default,
     * or the same as the new default once converted.
     */
    public static function upgrade_text($text, $old_default, $block, $new_default) {
        $text = self::normalise($text);
        if ($text === '' || $text === self::normalise($old_default)) {
            return '';
        }
        $converted = self::convert_legacy($text, $block);
        return $converted === self::normalise($new_default) ? '' : $converted;
    }

    /**
     * A pre-1.72 text in this format: only the layout changes, every word
     * the admin wrote is kept. $block is what replaces the figures lines:
     * 'fees' (the reminder) or 'details' (trial emails). Converting
     * converted text changes nothing.
     */
    public static function convert_legacy($text, $block) {
        $paragraphs = self::paragraphs(self::normalise($text));
        $last = count($paragraphs) - 1;
        $out = array();
        foreach ($paragraphs as $i => $paragraph) {
            $trimmed = trim($paragraph);
            $lines = explode("\n", $paragraph);
            if ($i === $last && $i > 0 && $trimmed === self::CLUB_NAME) {
                continue;
            }
            if ($trimmed === '{link}') {
                $out[] = '{button}';
                continue;
            }
            if (self::is_figures($lines, $block)) {
                $out[] = '{' . $block . '}';
                continue;
            }
            if (count($lines) > 1 && trim(end($lines)) === '{link}') {
                array_pop($lines);
                $words = rtrim(implode("\n", $lines));
                if (substr($words, -1) === ':') {
                    $words = substr($words, 0, -1) . '.';
                }
                $out[] = $words;
                $out[] = '{button}';
                continue;
            }
            if ($block === 'details' && strpos($trimmed, 'Coaches may ask for your trial number') === 0) {
                $out[] = '> ' . $trimmed;
                continue;
            }
            $out[] = $paragraph;
        }
        return implode("\n\n", $out);
    }

    /**
     * The old layout's figures: every line "Label: value" where the value
     * is only the figure placeholders ($ / # in front and "(unchanged)"
     * after allowed). Anything else in the paragraph keeps it as words.
     */
    private static function is_figures($lines, $block) {
        $values = $block === 'fees'
            ? '\$?\{(?:overdue|outstanding)\}'
            : '#?\{(?:trial_number|teams|positions|fee)\}(?:\s*\(unchanged\))?';
        foreach ($lines as $line) {
            if (!preg_match('/^\s*[A-Za-z][A-Za-z ]{0,30}:\s*' . $values . '\s*$/', $line)) {
                return false;
            }
        }
        return count($lines) > 0;
    }

    /** The 1.71 built-in wording, which saved text equal to it is reset from. */
    private static function legacy_defaults() {
        $sign_off = self::CLUB_NAME;
        return array(
            'reminder_subject' => 'MURVC club fees overdue — ${overdue}',
            'reminder_body' => "Hi {first_name},\n\n"
                . "Your MURVC club fees have been behind the payment schedule for {time_overdue}.\n\n"
                . "  Overdue now:     \${overdue}\n"
                . "  Total remaining: \${outstanding}\n\n"
                . "You can pay any amount online — part of it or all of it, whatever suits — and it comes straight off your balance:\n"
                . "{link}\n\n"
                . "If you think this is a mistake, or you'd like to arrange a payment plan, just reply to this email.\n\n"
                . $sign_off,
            'trial' => array(
                'submitted' => "Hi {first_name},\n\n"
                    . "Thanks for applying to trial with MURVC for the {season} season.\n\n"
                    . "  Trial number: #{trial_number}\n"
                    . "  Teams:        {teams}\n"
                    . "  Positions:    {positions}\n\n"
                    . "Coaches may ask for your trial number at trials — plenty of players write it on their hand or arm on the day.\n\n"
                    . "Need to change something? Your application stays editable until applications close:\n"
                    . "{link}\n\n"
                    . $sign_off,
                'payment_needed' => "Hi {first_name},\n\n"
                    . "Your {season} MURVC trial application has been saved, but it won't be confirmed until the trial fee ({fee}) is paid. Coaches can see your application, but can't select you for a team until it's paid.\n\n"
                    . "  Trial number: #{trial_number}\n"
                    . "  Teams:        {teams}\n\n"
                    . "You can pay from the trial page — look for the \"Pay the trial fee\" button:\n"
                    . "{link}\n\n"
                    . "Already paid, or think this is a mistake? Just reply to this email.\n\n"
                    . $sign_off,
                'paid' => "Hi {first_name},\n\n"
                    . "Thanks — we've received your trial fee, and your {season} application is confirmed.\n\n"
                    . "  Trial number: #{trial_number}\n"
                    . "  Teams:        {teams}\n"
                    . "  Positions:    {positions}\n\n"
                    . "Coaches may ask for your trial number at trials — plenty of players write it on their hand or arm on the day.\n\n"
                    . $sign_off,
                'edited' => "Hi {first_name},\n\n"
                    . "Your changes to your {season} MURVC trial application have been saved.\n\n"
                    . "  Trial number: #{trial_number} (unchanged)\n"
                    . "  Teams:        {teams}\n"
                    . "  Positions:    {positions}\n\n"
                    . "You can keep making changes until applications close:\n"
                    . "{link}\n\n"
                    . "If you didn't make this change, please reply to this email.\n\n"
                    . $sign_off,
            ),
        );
    }

    /**
     * Posted wording from an Emails page opened before 1.72 (it has no
     * format field) in this format, so old-layout text never comes back.
     */
    public static function upgrade_posted($text, $kind, $event = '') {
        $legacy = self::legacy_defaults();
        if ($kind === 'reminder') {
            return self::upgrade_text($text, $legacy['reminder_body'], 'fees', TeamOversight_Payments::get_default_reminder_body());
        }
        $events = TeamOversight_Trial_Emails::get_events();
        if (!isset($legacy['trial'][$event], $events[$event])) {
            return (string) $text;
        }
        return self::upgrade_text($text, $legacy['trial'][$event], 'details', $events[$event]['body']);
    }
}
