<?php
/**
 * ai_match.php — Intelligent plumber matching
 *
 * Not a standalone endpoint — included by jobs.php.
 * Two-step process:
 *   1. classify_issue()      → map free-text issue to a category
 *   2. classify_and_match()  → pick the best plumber for that category
 */

/* =====================================================
   STEP 1 — Classify the user's issue
   Uses keyword scoring across categories.
   ===================================================== */
function classify_issue(string $text): string
{
    $text = strtolower($text);

    // Each category has weighted keywords
    $categories = [
        'leak' => [
            'leak' => 3, 'drip' => 3, 'dripping' => 3, 'burst' => 3,
            'water coming' => 3, 'water leaking' => 3, 'wet' => 2,
            'puddle' => 2, 'seeping' => 2, 'pipe broken' => 3,
            'faucet' => 2, 'tap' => 2, 'valve' => 2
        ],
        'blockage' => [
            'block' => 3, 'blocked' => 3, 'clog' => 3, 'clogged' => 3,
            'drain' => 3, 'drainage' => 3, 'slow drain' => 3,
            'backup' => 2, 'backing up' => 3, 'toilet' => 2,
            'sink' => 2, 'overflow' => 2, 'stuck' => 2
        ],
        'installation' => [
            'install' => 3, 'installation' => 3, 'fit' => 2, 'fitting' => 2,
            'new' => 1, 'replace' => 2, 'replacement' => 2, 'connect' => 2,
            'setup' => 2, 'mount' => 2, 'renovate' => 2, 'renovation' => 3
        ],
        'heater' => [
            'heater' => 3, 'water heater' => 3, 'geyser' => 3,
            'boiler' => 3, 'hot water' => 3, 'no hot water' => 3,
            'cold water' => 2, 'thermostat' => 3, 'pilot' => 2
        ]
    ];

    // Score each category
    $scores = [];
    foreach ($categories as $cat => $keywords) {
        $score = 0;
        foreach ($keywords as $kw => $weight) {
            if (strpos($text, $kw) !== false) {
                $score += $weight;
            }
        }
        $scores[$cat] = $score;
    }

    // Find highest scoring category
    arsort($scores);
    $topCategory = array_key_first($scores);
    $topScore    = $scores[$topCategory];

    // If no keywords matched, return "general"
    return $topScore > 0 ? $topCategory : 'general';
}

/* =====================================================
   STEP 2 — Score & match the best plumber
   Weighted scoring:
     specialty match  → 40%
     level            → 30%
     rating           → 20%
     availability     → 10% (constant for available plumbers)
   ===================================================== */
function classify_and_match(PDO $pdo, string $description, ?int $exclude_plumber_id = null): array
{
    $category = classify_issue($description);

    // Level → numeric weight
    $levelWeights = [
        'beginner'     => 0.5,
        'intermediate' => 0.75,
        'expert'       => 1.0
    ];

    // Fetch all active, available plumbers (excluding one if requested)
    $sql = "SELECT
                p.id,
                p.level,
                p.specialization,
                p.rating_avg,
                p.availability,
                p.status,
                u.name AS plumber_name
            FROM plumbers p
            JOIN users u ON p.user_id = u.id
            WHERE p.availability = 1
              AND p.status = 'active'";
    $params = [];

    if ($exclude_plumber_id) {
        $sql .= " AND p.id <> ?";
        $params[] = $exclude_plumber_id;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $plumbers = $stmt->fetchAll();

    if (empty($plumbers)) {
        return [
            'category'     => $category,
            'plumber_id'   => null,
            'plumber_name' => null,
            'scores'       => []
        ];
    }

    // Score each plumber
    $scored = [];
    foreach ($plumbers as $p) {
        // Specialty match: full point if exact, half if general plumber taking any job
        if ($p['specialization'] === $category) {
            $specScore = 1.0;
        } elseif ($p['specialization'] === 'general') {
            $specScore = 0.5;
        } else {
            $specScore = 0.0;
        }

        $levelScore  = $levelWeights[$p['level']] ?? 0.5;
        $ratingScore = ((float)$p['rating_avg']) / 5.0;
        $availScore  = 1.0; // already filtered

        // Weighted sum
        $total = ($specScore  * 0.40)
               + ($levelScore * 0.30)
               + ($ratingScore* 0.20)
               + ($availScore * 0.10);

        $scored[] = [
            'plumber_id'   => (int)$p['id'],
            'plumber_name' => $p['plumber_name'],
            'level'        => $p['level'],
            'specialization' => $p['specialization'],
            'rating'       => (float)$p['rating_avg'],
            'score'        => round($total, 4)
        ];
    }

    // Sort highest first
    usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);

    return [
        'category'     => $category,
        'plumber_id'   => $scored[0]['plumber_id']   ?? null,
        'plumber_name' => $scored[0]['plumber_name'] ?? null,
        'scores'       => $scored
    ];
}