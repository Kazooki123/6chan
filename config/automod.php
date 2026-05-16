<?php

function automod_check(string $text, PDO $db): array {
    $rules = $db->query(
        "SELECT * FROM automod_rules ORDER BY rule_type"
    )->fetchAll(PDO::FETCH_ASSOC);

    $result = [
        'action' => 'allow',
        'reason' => null,
    ];

    foreach ($rules as $rule) {
        $matched = false;

        if ($rule['is_regex']) {
            $matched = (bool)preg_match('/' . $rule['pattern'] . '/iu', $text);
        } else {
            $matched = str_contains(mb_strtolower($text), mb_strtolower($rule['pattern']));
        }

        if (!$matched) continue;

        if ($rule['rule_type'] === 'block') {
            return ['action' => 'block', 'reason' => $rule['pattern']];
        }

        if ($rule['rule_type'] === 'flag') {
            $result = ['action' => 'flag', 'reason' => $rule['pattern']];
        }
    }
    return $result;
}

?>