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
        'reaction'    => 3,
        'gratitude'   => 5,
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
        'reaction'    => ['tenderness'=>4, 'complicity'=>3],
        'gratitude'   => ['tenderness'=>6, 'communication'=>4],
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
        // Ensure name_ru column exists
        try { $this->db->query("SELECT name_ru FROM couple_levels LIMIT 1"); } catch (\Exception $e) {
            try { $this->db->exec("ALTER TABLE couple_levels ADD COLUMN name_ru VARCHAR(50) DEFAULT NULL AFTER name_en"); } catch (\Exception $e2) {}
        }
        $stmt = $this->db->prepare("SELECT c.*, cl.name_fr AS level_name_fr, cl.name_en AS level_name_en, cl.name_ru AS level_name_ru, cl.emoji AS level_emoji FROM couples c LEFT JOIN couple_levels cl ON cl.level = c.level WHERE c.id = ?");
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
     * Level determines the shape (heart evolving into tree), mood determines color/animation
     */
    public function getAvatarSvg(int $level, string $mood): string {
        // Colors based on mood: [main, glow, glowOpacity]
        $colors = [
            'radiant' => ['#c9a96e', '#f0d890', '1'],
            'happy'   => ['#c9a96e', '#ddb870', '.8'],
            'serene'  => ['#a8956e', '#c0a870', '.6'],
            'tired'   => ['#8a7a60', '#9a8a68', '.4'],
            'sad'     => ['#6a6558', '#7a7060', '.25'],
            'sick'    => ['#c96e6e', '#8a5050', '.15'],
        ];
        $c = $colors[$mood] ?? $colors['serene'];
        $main = $c[0]; $glow = $c[1]; $glowOpacity = $c[2];

        // Animation speed based on mood
        $floatSpeed = match($mood) {
            'radiant' => '3s', 'happy' => '4s', 'serene' => '5s',
            'tired' => '7s', 'sad' => '0s', 'sick' => '2s',
            default => '5s'
        };

        // Float values for animation
        $floatY = match($mood) {
            'radiant' => '-8', 'happy' => '-6', 'serene' => '-4',
            'tired' => '-2', 'sad' => '0', 'sick' => '-1',
            default => '-4'
        };

        $uid = 'av' . mt_rand(1000,9999);
        $svg = '<svg viewBox="0 0 160 160" xmlns="http://www.w3.org/2000/svg">';

        // Defs: glow filter, gradients
        $svg .= "<defs>";
        $svg .= "<filter id='{$uid}glow'><feGaussianBlur stdDeviation='3' result='blur'/>";
        $svg .= "<feMerge><feMergeNode in='blur'/><feMergeNode in='SourceGraphic'/></feMerge></filter>";
        $svg .= "<radialGradient id='{$uid}rg'><stop offset='0%' stop-color='{$glow}' stop-opacity='{$glowOpacity}'/><stop offset='100%' stop-color='{$main}' stop-opacity='0'/></radialGradient>";
        $svg .= "<linearGradient id='{$uid}lg' x1='0' y1='0' x2='0' y2='1'><stop offset='0%' stop-color='{$glow}'/><stop offset='100%' stop-color='{$main}'/></linearGradient>";
        $svg .= "</defs>";

        // Ambient glow circle
        $svg .= "<circle cx='80' cy='80' r='75' fill='url(#{$uid}rg)' opacity='{$glowOpacity}'/>";

        // Main animated group
        $svg .= "<g filter='url(#{$uid}glow)'>";
        if ($mood !== 'sad') {
            $svg .= "<animateTransform attributeName='transform' type='translate' values='0,0;0,{$floatY};0,0' dur='{$floatSpeed}' repeatCount='indefinite'/>";
        }

        // Build level-specific SVG
        switch ($level) {
            case 1: // Etincelle — simple small heart, dim glow
                $svg .= $this->_svgHeart(80, 80, 28, $main, $glow, $glowOpacity);
                // Small sparkle
                $svg .= "<circle cx='80' cy='55' r='2' fill='{$glow}' opacity='0'>";
                $svg .= "<animate attributeName='opacity' values='0;{$glowOpacity};0' dur='3s' repeatCount='indefinite'/>";
                $svg .= "</circle>";
                break;

            case 2: // Flamme — heart with flame effect
                $svg .= $this->_svgHeart(80, 82, 32, $main, $glow, $glowOpacity);
                // Flame licks above the heart
                $flames = [[72,52,4],[80,46,5],[88,52,4]];
                foreach ($flames as $i => $f) {
                    $delay = $i * 0.3;
                    $svg .= "<ellipse cx='{$f[0]}' cy='{$f[1]}' rx='{$f[2]}' ry='8' fill='{$glow}' opacity='.6'>";
                    $svg .= "<animate attributeName='ry' values='8;14;8' dur='1.5s' begin='{$delay}s' repeatCount='indefinite'/>";
                    $svg .= "<animate attributeName='opacity' values='.6;.2;.6' dur='1.5s' begin='{$delay}s' repeatCount='indefinite'/>";
                    $svg .= "</ellipse>";
                }
                // Warm inner glow
                $svg .= "<circle cx='80' cy='80' r='18' fill='{$glow}' opacity='.15'/>";
                break;

            case 3: // Racines — heart with roots below
                $svg .= $this->_svgHeart(80, 72, 34, $main, $glow, $glowOpacity);
                // Roots growing below
                $svg .= "<path d='M80 100 Q75 115 70 130 Q68 135 65 140' fill='none' stroke='{$main}' stroke-width='2.5' stroke-linecap='round' opacity='.8'/>";
                $svg .= "<path d='M80 100 Q85 118 90 132 Q93 138 96 142' fill='none' stroke='{$main}' stroke-width='2' stroke-linecap='round' opacity='.7'/>";
                $svg .= "<path d='M80 100 Q78 112 76 120 Q72 128 68 132' fill='none' stroke='{$main}' stroke-width='1.5' stroke-linecap='round' opacity='.5'/>";
                $svg .= "<path d='M80 100 Q82 114 86 124 Q90 130 94 134' fill='none' stroke='{$main}' stroke-width='1.5' stroke-linecap='round' opacity='.5'/>";
                // Root tips (small circles)
                $rootTips = [[65,140],[96,142],[68,132],[94,134]];
                foreach ($rootTips as $rt) {
                    $svg .= "<circle cx='{$rt[0]}' cy='{$rt[1]}' r='1.5' fill='{$main}' opacity='.6'/>";
                }
                break;

            case 4: // Arbre — heart-shaped tree with branches
                // Trunk
                $svg .= "<rect x='77' y='90' width='6' height='40' rx='2' fill='{$main}' opacity='.9'/>";
                // Branches
                $svg .= "<path d='M80 100 Q60 90 50 75' fill='none' stroke='{$main}' stroke-width='2.5' stroke-linecap='round'/>";
                $svg .= "<path d='M80 100 Q100 90 110 75' fill='none' stroke='{$main}' stroke-width='2.5' stroke-linecap='round'/>";
                $svg .= "<path d='M80 95 Q55 80 45 60' fill='none' stroke='{$main}' stroke-width='2' stroke-linecap='round'/>";
                $svg .= "<path d='M80 95 Q105 80 115 60' fill='none' stroke='{$main}' stroke-width='2' stroke-linecap='round'/>";
                // Heart-shaped canopy
                $svg .= $this->_svgHeart(80, 58, 36, $main, $glow, $glowOpacity);
                // Small roots
                $svg .= "<path d='M80 130 Q72 140 65 145' fill='none' stroke='{$main}' stroke-width='1.5' stroke-linecap='round' opacity='.5'/>";
                $svg .= "<path d='M80 130 Q88 140 95 145' fill='none' stroke='{$main}' stroke-width='1.5' stroke-linecap='round' opacity='.5'/>";
                break;

            case 5: // Foret — lush heart-tree with leaves, particles
                // Trunk
                $svg .= "<rect x='76' y='85' width='8' height='45' rx='3' fill='{$main}' opacity='.9'/>";
                // Thick branches
                $svg .= "<path d='M80 95 Q55 82 42 65' fill='none' stroke='{$main}' stroke-width='3' stroke-linecap='round'/>";
                $svg .= "<path d='M80 95 Q105 82 118 65' fill='none' stroke='{$main}' stroke-width='3' stroke-linecap='round'/>";
                $svg .= "<path d='M80 90 Q50 70 38 48' fill='none' stroke='{$main}' stroke-width='2' stroke-linecap='round'/>";
                $svg .= "<path d='M80 90 Q110 70 122 48' fill='none' stroke='{$main}' stroke-width='2' stroke-linecap='round'/>";
                // Large heart canopy
                $svg .= $this->_svgHeart(80, 52, 40, $main, $glow, $glowOpacity);
                // Leaf particles floating around
                $leaves = [[45,40],[55,28],[105,35],[115,45],[65,22],[95,22],[50,55],[110,55]];
                foreach ($leaves as $i => $lf) {
                    $delay = $i * 0.4;
                    $svg .= "<circle cx='{$lf[0]}' cy='{$lf[1]}' r='3' fill='{$glow}' opacity='0'>";
                    $svg .= "<animate attributeName='opacity' values='0;.7;0' dur='3s' repeatCount='indefinite' begin='{$delay}s'/>";
                    $endY = $lf[1] - 15;
                    $svg .= "<animate attributeName='cy' values='{$lf[1]};{$endY}' dur='3s' repeatCount='indefinite' begin='{$delay}s'/>";
                    $svg .= "</circle>";
                }
                // Roots
                $svg .= "<path d='M80 130 Q68 142 58 150' fill='none' stroke='{$main}' stroke-width='2' stroke-linecap='round' opacity='.6'/>";
                $svg .= "<path d='M80 130 Q92 142 102 150' fill='none' stroke='{$main}' stroke-width='2' stroke-linecap='round' opacity='.6'/>";
                $svg .= "<path d='M80 130 Q80 145 80 152' fill='none' stroke='{$main}' stroke-width='1.5' stroke-linecap='round' opacity='.4'/>";
                break;

            case 6: // Legende — radiant heart with crown, sparkles
            default:
                // Outer radiance rings
                for ($r = 70; $r >= 50; $r -= 10) {
                    $op = round(0.05 + (70 - $r) * 0.02, 2);
                    $svg .= "<circle cx='80' cy='70' r='{$r}' fill='none' stroke='{$glow}' stroke-width='1' opacity='{$op}'/>";
                }
                // Grand heart
                $svg .= $this->_svgHeart(80, 72, 42, $main, $glow, $glowOpacity);
                // Crown above the heart
                $svg .= "<path d='M60 38 L68 28 L76 36 L80 22 L84 36 L92 28 L100 38 Z' fill='{$glow}' stroke='{$main}' stroke-width='1.5' opacity='.9'/>";
                // Crown jewels
                $svg .= "<circle cx='72' cy='32' r='2' fill='{$main}'/>";
                $svg .= "<circle cx='80' cy='26' r='2.5' fill='{$main}'/>";
                $svg .= "<circle cx='88' cy='32' r='2' fill='{$main}'/>";
                // Sparkle particles all around
                $sparkles = [
                    [40,30],[120,30],[30,60],[130,60],[35,90],[125,90],
                    [45,20],[115,20],[50,105],[110,105],[80,15],[80,135]
                ];
                foreach ($sparkles as $i => $sp) {
                    $delay = $i * 0.25;
                    $sz = ($i % 3 === 0) ? 2.5 : 1.5;
                    $svg .= "<circle cx='{$sp[0]}' cy='{$sp[1]}' r='{$sz}' fill='{$glow}' opacity='0'>";
                    $svg .= "<animate attributeName='opacity' values='0;1;0' dur='2s' repeatCount='indefinite' begin='{$delay}s'/>";
                    $endY = $sp[1] - 20;
                    $svg .= "<animate attributeName='cy' values='{$sp[1]};{$endY}' dur='2s' repeatCount='indefinite' begin='{$delay}s'/>";
                    $svg .= "</circle>";
                }
                // Inner radiant glow
                $svg .= "<circle cx='80' cy='72' r='20' fill='{$glow}' opacity='.2'>";
                $svg .= "<animate attributeName='r' values='20;25;20' dur='2s' repeatCount='indefinite'/>";
                $svg .= "</circle>";
                break;
        }

        // Sick mood: pulsing red overlay
        if ($mood === 'sick') {
            $svg .= "<circle cx='80' cy='80' r='50' fill='#c96e6e' opacity='0'>";
            $svg .= "<animate attributeName='opacity' values='0;.15;0' dur='2s' repeatCount='indefinite'/>";
            $svg .= "</circle>";
        }

        // Sad mood: grey overlay
        if ($mood === 'sad') {
            $svg .= "<circle cx='80' cy='80' r='50' fill='#555' opacity='.1'/>";
        }

        $svg .= "</g>"; // close animated group
        $svg .= '</svg>';
        return $svg;
    }

    /**
     * Helper: draw a heart shape at given center, size, colors
     */
    private function _svgHeart(int $cx, int $cy, int $size, string $main, string $glow, string $glowOpacity): string {
        // Heart path scaled relative to size
        $s = $size / 30; // normalize to scale factor
        // Heart shape: two cubic beziers from bottom point up through two bumps
        $bx = $cx; $by = $cy + (int)(12 * $s); // bottom point
        $topY = $cy - (int)(12 * $s);
        $midY = $cy - (int)(4 * $s);
        $ctrlSpread = (int)(16 * $s);
        $ctrlUp = (int)(28 * $s);
        $ctrlMid = (int)(6 * $s);

        $path = "M{$bx} {$by} "
            . "C" . ($bx - $ctrlMid) . " " . ($by - $ctrlMid) . " " . ($bx - $ctrlSpread) . " " . ($midY) . " " . ($bx - $ctrlSpread) . " " . ($topY) . " "
            . "C" . ($bx - $ctrlSpread) . " " . ($topY - $ctrlMid * 2) . " " . ($bx - $ctrlMid) . " " . ($topY - $ctrlMid * 2) . " " . $bx . " " . ($topY + $ctrlMid) . " "
            . "C" . ($bx + $ctrlMid) . " " . ($topY - $ctrlMid * 2) . " " . ($bx + $ctrlSpread) . " " . ($topY - $ctrlMid * 2) . " " . ($bx + $ctrlSpread) . " " . ($topY) . " "
            . "C" . ($bx + $ctrlSpread) . " " . ($midY) . " " . ($bx + $ctrlMid) . " " . ($by - $ctrlMid) . " " . $bx . " " . $by . " Z";

        $svg = "<path d='{$path}' fill='{$main}' stroke='{$glow}' stroke-width='1.5' opacity='.9'/>";
        // Inner highlight
        $innerSize = (int)($size * 0.5);
        $svg .= "<circle cx='{$cx}' cy='" . ($cy - (int)(2 * $s)) . "' r='{$innerSize}' fill='{$glow}' opacity='.15'/>";
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
