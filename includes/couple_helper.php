<?php
/**
 * NATACHA — Couple Entity Helper
 * Gère la logique de l'entité "couple" : jauges, niveaux, humeur, XP, déclin
 */

class CoupleEntity {

    // XP rewards per activity
    const XP_REWARDS = [
        'histoire'    => 20,
        'defi'        => 15,
        'jeu'         => 10,
        'lieu'        => 15,
        'mot'         => 5,
        'photo'       => 10,
        'calendrier'  => 10,
        'musique'     => 5,
        'film'        => 5,
        'questionnaire' => 15,
    ];

    // Gauge impacts per activity type
    const GAUGE_IMPACTS = [
        'histoire'    => ['communication'=>8, 'tenderness'=>5, 'complicity'=>5],
        'defi'        => ['surprise'=>8, 'adventure'=>5, 'complicity'=>3],
        'jeu'         => ['complicity'=>8, 'surprise'=>3, 'communication'=>3],
        'lieu'        => ['adventure'=>10, 'complicity'=>3, 'surprise'=>3],
        'mot'         => ['tenderness'=>10, 'communication'=>5],
        'photo'       => ['tenderness'=>5, 'complicity'=>5],
        'calendrier'  => ['adventure'=>5, 'surprise'=>5, 'communication'=>3],
        'musique'     => ['complicity'=>5, 'tenderness'=>3],
        'film'        => ['complicity'=>5, 'tenderness'=>3],
        'questionnaire' => ['communication'=>8, 'complicity'=>5, 'surprise'=>3],
    ];

    // Daily gauge decay
    const DECAY_RATE = 2;

    // Mood thresholds based on average gauge
    const MOOD_THRESHOLDS = [
        'radiant' => 80,
        'happy'   => 65,
        'serene'  => 50,
        'tired'   => 35,
        'sad'     => 20,
        'sick'    => 0,
    ];

    private PDO $db;

    public function __construct(PDO $db) {
        $this->db = $db;
    }

    /**
     * Get couple data with computed fields
     */
    public function getCouple(int $coupleId): ?array {
        $stmt = $this->db->prepare("SELECT c.*, cl.name_fr AS level_name_fr, cl.name_en AS level_name_en, cl.emoji AS level_emoji FROM couples c LEFT JOIN couple_levels cl ON cl.level = c.level WHERE c.id = ?");
        $stmt->execute([$coupleId]);
        $couple = $stmt->fetch();
        if (!$couple) return null;

        // Compute age
        $birth = new DateTime($couple['birth_date']);
        $now = new DateTime();
        $diff = $birth->diff($now);
        $couple['age_days'] = (int)$diff->format('%a');
        $couple['age_years'] = $diff->y;
        $couple['age_months'] = $diff->m;
        $couple['age_label_fr'] = $this->formatAge($diff, 'fr');
        $couple['age_label_en'] = $this->formatAge($diff, 'en');
        $couple['age_label_ru'] = $this->formatAge($diff, 'ru');

        // Compute average gauge
        $couple['avg_gauge'] = round(($couple['gauge_communication'] + $couple['gauge_adventure'] + $couple['gauge_tenderness'] + $couple['gauge_surprise'] + $couple['gauge_complicity']) / 5);

        // Get members
        $stmt = $this->db->prepare("SELECT id, display_name, avatar, role, lang FROM users WHERE couple_id = ?");
        $stmt->execute([$coupleId]);
        $couple['members'] = $stmt->fetchAll();

        return $couple;
    }

    /**
     * Format age difference for display
     */
    private function formatAge(DateInterval $diff, string $lang): string {
        $parts = [];
        if ($diff->y > 0) {
            if ($lang === 'ru') {
                $y = $diff->y;
                $mod = $y % 10;
                $mod100 = $y % 100;
                if ($mod === 1 && $mod100 !== 11) $parts[] = "$y год";
                elseif ($mod >= 2 && $mod <= 4 && ($mod100 < 12 || $mod100 > 14)) $parts[] = "$y года";
                else $parts[] = "$y лет";
            } else {
                $parts[] = $diff->y . ($diff->y > 1 ? ' ans' : ' an');
            }
        }
        if ($diff->m > 0) {
            if ($lang === 'ru') {
                $m = $diff->m;
                $mod = $m % 10;
                if ($mod === 1) $parts[] = "$m месяц";
                elseif ($mod >= 2 && $mod <= 4) $parts[] = "$m месяца";
                else $parts[] = "$m месяцев";
            } else {
                $parts[] = $diff->m . ' mois';
            }
        }
        if ($diff->y === 0 && $diff->m === 0) {
            $d = (int)$diff->format('%a');
            if ($lang === 'ru') {
                $mod = $d % 10;
                $mod100 = $d % 100;
                if ($mod === 1 && $mod100 !== 11) $parts[] = "$d день";
                elseif ($mod >= 2 && $mod <= 4 && ($mod100 < 12 || $mod100 > 14)) $parts[] = "$d дня";
                else $parts[] = "$d дней";
            } else {
                $parts[] = $d . ($d > 1 ? ' jours' : ' jour');
            }
        }
        return implode(', ', $parts);
    }

    /**
     * Record an activity and update gauges/XP
     */
    public function recordActivity(int $coupleId, int $userId, string $type, ?string $descFr = null, ?string $descEn = null): void {
        $xp = self::XP_REWARDS[$type] ?? 10;
        $impacts = self::GAUGE_IMPACTS[$type] ?? [];

        $this->db->prepare("INSERT INTO couple_activities (couple_id, user_id, activity_type, xp_earned, gauge_impacts, description_fr, description_en) VALUES (?,?,?,?,?,?,?)")
            ->execute([$coupleId, $userId, $type, $xp, json_encode($impacts), $descFr, $descEn]);

        // Update couple gauges + XP
        $sets = ["xp = xp + ?"];
        $params = [$xp];

        foreach ($impacts as $gauge => $amount) {
            $col = 'gauge_' . $gauge;
            $sets[] = "$col = LEAST(100, $col + ?)";
            $params[] = $amount;
        }

        $params[] = $coupleId;
        $this->db->prepare("UPDATE couples SET " . implode(', ', $sets) . " WHERE id = ?")
            ->execute($params);

        // Update mood and level
        $this->updateMood($coupleId);
        $this->checkLevelUp($coupleId);
    }

    /**
     * Apply daily gauge decay (called once per day)
     */
    public function applyDailyDecay(int $coupleId): bool {
        $today = date('Y-m-d');

        // Check if already decayed today
        $stmt = $this->db->prepare("SELECT 1 FROM gauge_decay_log WHERE couple_id = ? AND decayed_at = ?");
        $stmt->execute([$coupleId, $today]);
        if ($stmt->fetch()) return false;

        // Apply decay
        $this->db->prepare("UPDATE couples SET
            gauge_communication = GREATEST(0, gauge_communication - ?),
            gauge_adventure     = GREATEST(0, gauge_adventure - ?),
            gauge_tenderness    = GREATEST(0, gauge_tenderness - ?),
            gauge_surprise      = GREATEST(0, gauge_surprise - ?),
            gauge_complicity    = GREATEST(0, gauge_complicity - ?)
            WHERE id = ?")
            ->execute([self::DECAY_RATE, self::DECAY_RATE, self::DECAY_RATE, self::DECAY_RATE, self::DECAY_RATE, $coupleId]);

        // Log decay
        $this->db->prepare("INSERT IGNORE INTO gauge_decay_log (couple_id, decayed_at) VALUES (?, ?)")
            ->execute([$coupleId, $today]);

        $this->updateMood($coupleId);
        return true;
    }

    /**
     * Update mood based on average gauge value
     */
    private function updateMood(int $coupleId): void {
        $stmt = $this->db->prepare("SELECT (gauge_communication + gauge_adventure + gauge_tenderness + gauge_surprise + gauge_complicity) / 5 AS avg_gauge FROM couples WHERE id = ?");
        $stmt->execute([$coupleId]);
        $avg = (int)($stmt->fetchColumn() ?? 50);

        $mood = 'sick';
        foreach (self::MOOD_THRESHOLDS as $m => $threshold) {
            if ($avg >= $threshold) {
                $mood = $m;
                break;
            }
        }

        $this->db->prepare("UPDATE couples SET mood = ? WHERE id = ?")->execute([$mood, $coupleId]);
    }

    /**
     * Check if couple qualifies for level up
     */
    private function checkLevelUp(int $coupleId): void {
        $stmt = $this->db->prepare("SELECT * FROM couples WHERE id = ?");
        $stmt->execute([$coupleId]);
        $couple = $stmt->fetch();
        if (!$couple) return;

        $ageDays = (int)((time() - strtotime($couple['birth_date'])) / 86400);
        $avgGauge = ($couple['gauge_communication'] + $couple['gauge_adventure'] + $couple['gauge_tenderness'] + $couple['gauge_surprise'] + $couple['gauge_complicity']) / 5;

        $levels = $this->db->query("SELECT * FROM couple_levels ORDER BY level DESC")->fetchAll();
        foreach ($levels as $lvl) {
            if ($ageDays >= $lvl['min_days'] && $couple['xp'] >= $lvl['min_xp'] && $avgGauge >= $lvl['min_avg_gauge']) {
                if ($lvl['level'] > $couple['level']) {
                    $this->db->prepare("UPDATE couples SET level = ? WHERE id = ?")->execute([$lvl['level'], $coupleId]);
                }
                break;
            }
        }
    }

    /**
     * Get recent activities for couple
     */
    public function getRecentActivities(int $coupleId, int $limit = 10): array {
        $stmt = $this->db->prepare("SELECT ca.*, u.display_name FROM couple_activities ca JOIN users u ON u.id = ca.user_id WHERE ca.couple_id = ? ORDER BY ca.created_at DESC LIMIT ?");
        $stmt->execute([$coupleId, $limit]);
        return $stmt->fetchAll();
    }

    /**
     * Get the weakest gauge (for suggestions)
     */
    public function getWeakestGauge(array $couple): array {
        $gauges = [
            'communication' => $couple['gauge_communication'],
            'adventure'     => $couple['gauge_adventure'],
            'tenderness'    => $couple['gauge_tenderness'],
            'surprise'      => $couple['gauge_surprise'],
            'complicity'    => $couple['gauge_complicity'],
        ];
        asort($gauges);
        $weakest = array_key_first($gauges);
        return ['gauge' => $weakest, 'value' => $gauges[$weakest]];
    }

    /**
     * Suggest an action to boost the weakest gauge
     */
    public function getSuggestion(array $couple, string $lang = 'fr'): array {
        $weak = $this->getWeakestGauge($couple);
        $name = $couple['name'];

        $suggestions = [
            'communication' => [
                'fr' => "$name a besoin de dialoguer... Écrivez un chapitre de votre histoire ensemble.",
                'ru' => "$name нуждается в общении... Напишите главу вашей истории вместе.",
                'action' => 'histoire',
                'icon' => '💬',
            ],
            'adventure' => [
                'fr' => "$name rêve d'évasion... Ajoutez un nouveau lieu à explorer sur la carte.",
                'ru' => "$name мечтает о приключении... Добавьте новое место на карту.",
                'action' => 'carte',
                'icon' => '🗺️',
            ],
            'tenderness' => [
                'fr' => "$name a besoin de douceur... Envoyez un mot du jour à votre moitié.",
                'ru' => "$name нуждается в нежности... Отправьте записку дня своей половинке.",
                'action' => 'mot',
                'icon' => '💌',
            ],
            'surprise' => [
                'fr' => "$name manque de piment... Relevez un défi surprise ensemble !",
                'ru' => "$name не хватает остроты... Примите вызов-сюрприз вместе!",
                'action' => 'defis',
                'icon' => '🎲',
            ],
            'complicity' => [
                'fr' => "$name veut plus de complicité... Jouez à un jeu ensemble !",
                'ru' => "$name хочет больше близости... Сыграйте в игру вместе!",
                'action' => 'jeux',
                'icon' => '🎮',
            ],
        ];

        $s = $suggestions[$weak['gauge']] ?? $suggestions['tenderness'];
        return [
            'text' => $s[$lang] ?? $s['fr'],
            'action' => $s['action'],
            'icon' => $s['icon'],
            'gauge' => $weak['gauge'],
            'value' => $weak['value'],
        ];
    }

    /**
     * Get avatar SVG based on level and mood
     */
    public function getAvatarSvg(int $level, string $mood): string {
        // Colors based on mood
        $colors = [
            'radiant' => ['#c9a96e', '#e8d5a3', '#fff8e7'],
            'happy'   => ['#c9a96e', '#dcc48a', '#f0e6c8'],
            'serene'  => ['#a89060', '#c4ad7a', '#ddd0a8'],
            'tired'   => ['#8a7a5a', '#a09070', '#b8a888'],
            'sad'     => ['#6a6050', '#807060', '#988878'],
            'sick'    => ['#5a5040', '#6a6050', '#787060'],
        ];
        $c = $colors[$mood] ?? $colors['happy'];

        // Face expression based on mood
        $faces = [
            'radiant' => ['eyes'=>'happy', 'mouth'=>'big_smile'],
            'happy'   => ['eyes'=>'normal', 'mouth'=>'smile'],
            'serene'  => ['eyes'=>'calm', 'mouth'=>'gentle'],
            'tired'   => ['eyes'=>'droopy', 'mouth'=>'flat'],
            'sad'     => ['eyes'=>'sad', 'mouth'=>'frown'],
            'sick'    => ['eyes'=>'x', 'mouth'=>'wavy'],
        ];
        $face = $faces[$mood] ?? $faces['happy'];

        // Size grows with level
        $sizes = [1=>40, 2=>50, 3=>60, 4=>70, 5=>80, 6=>90];
        $size = $sizes[$level] ?? 50;
        $cx = 100; $cy = 100;

        $svg = '<svg viewBox="0 0 200 200" xmlns="http://www.w3.org/2000/svg">';

        // Glow for higher levels
        if ($level >= 3) {
            $svg .= "<defs><radialGradient id='glow'><stop offset='0%' stop-color='{$c[0]}' stop-opacity='0.3'/><stop offset='100%' stop-color='{$c[0]}' stop-opacity='0'/></radialGradient></defs>";
            $svg .= "<circle cx='$cx' cy='$cy' r='" . ($size+20) . "' fill='url(#glow)'/>";
        }

        // Body (circle that grows)
        $svg .= "<circle cx='$cx' cy='$cy' r='$size' fill='{$c[1]}' stroke='{$c[0]}' stroke-width='2'/>";

        // Inner glow
        $svg .= "<circle cx='$cx' cy='" . ($cy-5) . "' r='" . ($size-10) . "' fill='{$c[2]}' opacity='0.3'/>";

        // Eyes
        $eyeL = $cx - 15;
        $eyeR = $cx + 15;
        $eyeY = $cy - 8;

        switch ($face['eyes']) {
            case 'happy':
                $svg .= "<path d='M" . ($eyeL-6) . " $eyeY Q$eyeL " . ($eyeY-8) . " " . ($eyeL+6) . " $eyeY' fill='none' stroke='{$c[0]}' stroke-width='2.5' stroke-linecap='round'/>";
                $svg .= "<path d='M" . ($eyeR-6) . " $eyeY Q$eyeR " . ($eyeY-8) . " " . ($eyeR+6) . " $eyeY' fill='none' stroke='{$c[0]}' stroke-width='2.5' stroke-linecap='round'/>";
                break;
            case 'calm':
                $svg .= "<line x1='" . ($eyeL-5) . "' y1='$eyeY' x2='" . ($eyeL+5) . "' y2='$eyeY' stroke='{$c[0]}' stroke-width='2' stroke-linecap='round'/>";
                $svg .= "<line x1='" . ($eyeR-5) . "' y1='$eyeY' x2='" . ($eyeR+5) . "' y2='$eyeY' stroke='{$c[0]}' stroke-width='2' stroke-linecap='round'/>";
                break;
            case 'droopy':
                $svg .= "<ellipse cx='$eyeL' cy='$eyeY' rx='4' ry='3' fill='{$c[0]}' opacity='0.6'/>";
                $svg .= "<ellipse cx='$eyeR' cy='$eyeY' rx='4' ry='3' fill='{$c[0]}' opacity='0.6'/>";
                break;
            case 'sad':
                $svg .= "<ellipse cx='$eyeL' cy='$eyeY' rx='4' ry='5' fill='{$c[0]}'/>";
                $svg .= "<ellipse cx='$eyeR' cy='$eyeY' rx='4' ry='5' fill='{$c[0]}'/>";
                // Tears
                $svg .= "<ellipse cx='" . ($eyeL+2) . "' cy='" . ($eyeY+10) . "' rx='2' ry='3' fill='#6ea8c9' opacity='0.6'/>";
                break;
            case 'x':
                $svg .= "<line x1='" . ($eyeL-4) . "' y1='" . ($eyeY-4) . "' x2='" . ($eyeL+4) . "' y2='" . ($eyeY+4) . "' stroke='{$c[0]}' stroke-width='2'/>";
                $svg .= "<line x1='" . ($eyeL+4) . "' y1='" . ($eyeY-4) . "' x2='" . ($eyeL-4) . "' y2='" . ($eyeY+4) . "' stroke='{$c[0]}' stroke-width='2'/>";
                $svg .= "<line x1='" . ($eyeR-4) . "' y1='" . ($eyeY-4) . "' x2='" . ($eyeR+4) . "' y2='" . ($eyeY+4) . "' stroke='{$c[0]}' stroke-width='2'/>";
                $svg .= "<line x1='" . ($eyeR+4) . "' y1='" . ($eyeY-4) . "' x2='" . ($eyeR-4) . "' y2='" . ($eyeY+4) . "' stroke='{$c[0]}' stroke-width='2'/>";
                break;
            default: // normal
                $svg .= "<circle cx='$eyeL' cy='$eyeY' r='4' fill='{$c[0]}'/>";
                $svg .= "<circle cx='$eyeR' cy='$eyeY' r='4' fill='{$c[0]}'/>";
        }

        // Mouth
        $mouthY = $cy + 10;
        switch ($face['mouth']) {
            case 'big_smile':
                $svg .= "<path d='M" . ($cx-15) . " $mouthY Q$cx " . ($mouthY+18) . " " . ($cx+15) . " $mouthY' fill='none' stroke='{$c[0]}' stroke-width='2.5' stroke-linecap='round'/>";
                break;
            case 'smile':
                $svg .= "<path d='M" . ($cx-12) . " $mouthY Q$cx " . ($mouthY+12) . " " . ($cx+12) . " $mouthY' fill='none' stroke='{$c[0]}' stroke-width='2' stroke-linecap='round'/>";
                break;
            case 'gentle':
                $svg .= "<path d='M" . ($cx-8) . " $mouthY Q$cx " . ($mouthY+6) . " " . ($cx+8) . " $mouthY' fill='none' stroke='{$c[0]}' stroke-width='2' stroke-linecap='round'/>";
                break;
            case 'flat':
                $svg .= "<line x1='" . ($cx-10) . "' y1='$mouthY' x2='" . ($cx+10) . "' y2='$mouthY' stroke='{$c[0]}' stroke-width='2' stroke-linecap='round'/>";
                break;
            case 'frown':
                $svg .= "<path d='M" . ($cx-10) . " " . ($mouthY+5) . " Q$cx " . ($mouthY-5) . " " . ($cx+10) . " " . ($mouthY+5) . "' fill='none' stroke='{$c[0]}' stroke-width='2' stroke-linecap='round'/>";
                break;
            case 'wavy':
                $svg .= "<path d='M" . ($cx-10) . " $mouthY Q" . ($cx-5) . " " . ($mouthY-4) . " $cx $mouthY Q" . ($cx+5) . " " . ($mouthY+4) . " " . ($cx+10) . " $mouthY' fill='none' stroke='{$c[0]}' stroke-width='2' stroke-linecap='round'/>";
                break;
        }

        // Level decorations
        if ($level >= 2) {
            // Small hearts/leaves/stars around based on level
            $decorations = [2=>'🔥', 3=>'🌿', 4=>'🌳', 5=>'🌲', 6=>'⭐'];
            // We'll add CSS-based decorations on the page instead
        }

        $svg .= '</svg>';
        return $svg;
    }

    /**
     * Get mood label
     */
    public static function getMoodLabel(string $mood, string $lang = 'fr'): string {
        $labels = [
            'radiant' => ['fr'=>'Rayonnant', 'ru'=>'Сияющий'],
            'happy'   => ['fr'=>'Heureux', 'ru'=>'Счастливый'],
            'serene'  => ['fr'=>'Serein', 'ru'=>'Спокойный'],
            'tired'   => ['fr'=>'Fatigué', 'ru'=>'Уставший'],
            'sad'     => ['fr'=>'Triste', 'ru'=>'Грустный'],
            'sick'    => ['fr'=>'Malade', 'ru'=>'Болеет'],
        ];
        return $labels[$mood][$lang] ?? $labels[$mood]['fr'] ?? $mood;
    }

    /**
     * Get mood emoji
     */
    public static function getMoodEmoji(string $mood): string {
        $emojis = [
            'radiant'=>'✨', 'happy'=>'💛', 'serene'=>'😌',
            'tired'=>'😴', 'sad'=>'😢', 'sick'=>'🤒'
        ];
        return $emojis[$mood] ?? '💛';
    }
}
