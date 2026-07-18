<?php
/**
 * NATACHA — Landing page publique
 * Quiz : "Si votre couple était une personne, qui serait-il ?"
 * Flow : quiz → résultat personnalité → CTA inscription
 */
require_once __DIR__.'/config.php';
startSession();

// Already logged in? Go to dashboard
if (!empty($_SESSION['user_id'])) {
    header('Location: '.BASE_URL.'/dashboard.php');
    exit;
}

$lang = $_GET['lang'] ?? $_COOKIE['natacha_lang'] ?? 'fr';
if (!in_array($lang, ['fr','ru'])) $lang = 'fr';
setcookie('natacha_lang', $lang, time()+86400*365, '/');
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>Natacha — <?= $lang === 'fr' ? 'Donnez vie à votre couple' : 'Bring your couple to life' ?></title>
<meta name="description" content="<?= $lang === 'fr' ? 'Et si votre couple était une personne ? Découvrez sa personnalité, prenez-en soin, faites-le grandir.' : 'What if your couple was a person? Discover its personality, take care of it, help it grow.' ?>">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,600;1,300;1,400&family=DM+Mono:wght@300;400&display=swap" rel="stylesheet">
<style>
:root{--bg:#0f0d0b;--s:#141210;--border:#2e2a25;--accent:#c9a96e;--as:rgba(201,169,110,.12);--text:#e8e0d5;--muted:#7a7268;--danger:#c96e6e;--success:#6ec96e}
*{box-sizing:border-box;margin:0;padding:0}
html{scroll-behavior:smooth}
body{background:var(--bg);color:var(--text);font-family:'DM Mono',monospace;min-height:100vh;overflow-x:hidden;font-size:14px}
body::before{content:'';position:fixed;inset:0;background-image:url("data:image/svg+xml,%3Csvg viewBox='0 0 200 200' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='.85' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='.04'/%3E%3C/svg%3E");pointer-events:none;z-index:999;opacity:.4}
a{color:var(--accent);text-decoration:none}

/* ── Lang bar ── */
.lang-bar{position:fixed;top:1rem;right:1.5rem;display:flex;gap:.4rem;z-index:100}
.lb{font-size:.6rem;letter-spacing:.15em;text-transform:uppercase;background:transparent;border:1px solid var(--border);color:var(--muted);padding:.3rem .6rem;cursor:pointer;transition:all .2s;text-decoration:none}
.lb.active{border-color:var(--accent);color:var(--accent);background:var(--as)}

/* ── Hero ── */
.hero{min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;padding:2rem 1.5rem;position:relative}
.hero-badge{font-size:.55rem;letter-spacing:.25em;text-transform:uppercase;color:var(--accent);border:1px solid var(--accent);padding:.4rem 1rem;margin-bottom:2rem;display:inline-block}
.hero h1{font-family:'Cormorant Garamond',serif;font-size:clamp(2rem,6vw,3.5rem);font-weight:300;font-style:italic;color:var(--accent);line-height:1.2;margin-bottom:1rem;max-width:600px}
.hero p{font-size:.8rem;color:var(--muted);max-width:440px;line-height:1.8;margin-bottom:2.5rem}
.hero-cta{display:inline-block;background:var(--accent);color:var(--bg);font-family:'DM Mono',monospace;font-size:.72rem;letter-spacing:.2em;text-transform:uppercase;padding:1rem 2.5rem;border:none;cursor:pointer;transition:all .3s}
.hero-cta:hover{opacity:.85;transform:translateY(-2px)}
.scroll-hint{position:absolute;bottom:2rem;color:var(--muted);font-size:.65rem;letter-spacing:.15em;text-transform:uppercase;animation:pulse 2s infinite}
@keyframes pulse{0%,100%{opacity:.4}50%{opacity:1}}

/* ── Concept section ── */
.concept{padding:6rem 1.5rem;text-align:center}
.concept h2{font-family:'Cormorant Garamond',serif;font-size:1.6rem;font-weight:300;color:var(--accent);margin-bottom:1rem}
.concept p{font-size:.75rem;color:var(--muted);max-width:500px;margin:0 auto 3rem;line-height:1.8}
.features{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1.5rem;max-width:800px;margin:0 auto}
.feat{border:1px solid var(--border);padding:2rem 1.5rem;text-align:left;transition:all .3s}
.feat:hover{border-color:var(--accent);transform:translateY(-4px)}
.feat-icon{font-size:1.8rem;margin-bottom:1rem}
.feat h3{font-family:'Cormorant Garamond',serif;font-size:1rem;font-weight:400;color:var(--text);margin-bottom:.5rem}
.feat p{font-size:.68rem;color:var(--muted);line-height:1.7;margin:0}

/* ── Quiz section ── */
.quiz-section{padding:4rem 1.5rem 6rem;max-width:600px;margin:0 auto}
.quiz-section h2{font-family:'Cormorant Garamond',serif;font-size:1.6rem;font-weight:300;color:var(--accent);text-align:center;margin-bottom:.5rem}
.quiz-sub{text-align:center;font-size:.68rem;color:var(--muted);margin-bottom:3rem}

/* Progress */
.quiz-progress{display:flex;gap:4px;margin-bottom:2.5rem}
.quiz-progress .dot{flex:1;height:3px;background:var(--border);transition:background .4s}
.quiz-progress .dot.done{background:var(--accent)}
.quiz-progress .dot.active{background:var(--accent);animation:dotPulse 1.5s infinite}
@keyframes dotPulse{0%,100%{opacity:.5}50%{opacity:1}}

/* Question */
.quiz-question{animation:fadeUp .4s ease}
@keyframes fadeUp{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}
.q-number{font-size:.55rem;letter-spacing:.2em;text-transform:uppercase;color:var(--muted);margin-bottom:.8rem}
.q-text{font-family:'Cormorant Garamond',serif;font-size:1.2rem;font-weight:400;font-style:italic;color:var(--text);margin-bottom:2rem;line-height:1.5}

/* Options */
.q-options{display:flex;flex-direction:column;gap:.6rem}
.q-opt{display:block;width:100%;background:transparent;border:1px solid var(--border);color:var(--text);font-family:'DM Mono',monospace;font-size:.75rem;padding:1rem 1.2rem;cursor:pointer;text-align:left;transition:all .25s;line-height:1.5}
.q-opt:hover{border-color:var(--accent);color:var(--accent);background:var(--as);transform:translateX(4px)}
.q-opt.selected{border-color:var(--accent);color:var(--accent);background:var(--as)}

/* Result */
.quiz-result{text-align:center;animation:fadeUp .6s ease;display:none}
.result-emoji{font-size:4rem;margin-bottom:1.5rem}
.result-type{font-family:'Cormorant Garamond',serif;font-size:1.8rem;font-weight:300;font-style:italic;color:var(--accent);margin-bottom:.5rem}
.result-desc{font-size:.75rem;color:var(--muted);line-height:1.8;max-width:400px;margin:0 auto 1rem}

/* Gauges preview */
.result-gauges{max-width:300px;margin:2rem auto;text-align:left}
.gauge-row{display:flex;align-items:center;gap:.8rem;margin-bottom:.8rem}
.gauge-label{font-size:.6rem;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);width:100px;flex-shrink:0}
.gauge-bar{flex:1;height:6px;background:var(--border);position:relative;overflow:hidden}
.gauge-fill{height:100%;background:var(--accent);transition:width 1.5s cubic-bezier(.4,0,.2,1)}
.gauge-val{font-size:.6rem;color:var(--accent);width:30px;text-align:right}

.result-cta{display:inline-block;background:var(--accent);color:var(--bg);font-family:'DM Mono',monospace;font-size:.72rem;letter-spacing:.2em;text-transform:uppercase;padding:1rem 2.5rem;margin-top:2rem;border:none;cursor:pointer;transition:all .3s;text-decoration:none}
.result-cta:hover{opacity:.85;transform:translateY(-2px)}

/* ── Footer ── */
.landing-footer{text-align:center;padding:3rem 1.5rem;border-top:1px solid var(--border)}
.landing-footer p{font-size:.6rem;color:var(--muted);letter-spacing:.1em}

/* ── Mobile ── */
@media(max-width:600px){
    .features{grid-template-columns:1fr}
    .hero h1{font-size:1.8rem}
}
</style>
</head>
<body>

<div class="lang-bar">
    <a class="lb" href="<?= BASE_URL ?>/login.php" style="border-color:var(--accent);color:var(--accent)"><?= $lang==='fr'?'Connexion':'Вход' ?></a>
    <a class="lb <?= $lang==='fr'?'active':'' ?>" href="?lang=fr">FR</a>
    <a class="lb <?= $lang==='ru'?'active':'' ?>" href="?lang=ru">RU</a>
</div>

<!-- ═══ HERO ═══ -->
<section class="hero">
    <div class="hero-badge"><?= $lang==='fr' ? 'votre couple mérite une identité' : 'your couple deserves an identity' ?></div>
    <h1><?= $lang==='fr' ? 'Et si votre couple était une personne ?' : 'What if your couple was a person?' ?></h1>
    <p><?= $lang==='fr'
        ? 'Donnez-lui un prénom. Découvrez sa personnalité. Prenez-en soin chaque jour. Regardez-le grandir.'
        : 'Give it a name. Discover its personality. Take care of it every day. Watch it grow.' ?></p>
    <a href="#quiz" class="hero-cta"><?= $lang==='fr' ? 'Découvrir qui il est' : 'Discover who it is' ?></a>
    <div style="margin-top:1.2rem">
        <a href="<?= BASE_URL ?>/login.php" style="font-size:.68rem;color:var(--muted);letter-spacing:.1em"><?= $lang==='fr' ? 'Déjà un compte ? Se connecter →' : 'Already have an account? Log in →' ?></a>
    </div>
    <div class="scroll-hint">↓</div>
</section>

<!-- ═══ CONCEPT ═══ -->
<section class="concept">
    <h2><?= $lang==='fr' ? 'Votre couple est un être vivant' : 'Your couple is a living being' ?></h2>
    <p><?= $lang==='fr'
        ? 'Il n\'est ni toi, ni l\'autre. C\'est une troisième entité qui naît entre vous. Elle a ses besoins, ses envies, ses humeurs. À vous de la faire grandir.'
        : 'It\'s neither you nor the other. It\'s a third entity born between you. It has its needs, desires, moods. It\'s up to you to help it grow.' ?></p>

    <div class="features">
        <div class="feat">
            <div class="feat-icon">🌱</div>
            <h3><?= $lang==='fr' ? 'Il naît' : 'It\'s born' ?></h3>
            <p><?= $lang==='fr'
                ? 'Donnez un prénom à votre couple. Il commence tout petit, fragile, plein de promesses.'
                : 'Give your couple a name. It starts small, fragile, full of promise.' ?></p>
        </div>
        <div class="feat">
            <div class="feat-icon">💛</div>
            <h3><?= $lang==='fr' ? 'Il a des besoins' : 'It has needs' ?></h3>
            <p><?= $lang==='fr'
                ? 'Communication, aventure, tendresse, surprise, complicité. Cinq jauges à entretenir.'
                : 'Communication, adventure, tenderness, surprise, complicity. Five gauges to maintain.' ?></p>
        </div>
        <div class="feat">
            <div class="feat-icon">🌳</div>
            <h3><?= $lang==='fr' ? 'Il grandit' : 'It grows' ?></h3>
            <p><?= $lang==='fr'
                ? 'D\'étincelle à légende. Écrivez votre histoire, relevez des défis, partagez des moments.'
                : 'From spark to legend. Write your story, take on challenges, share moments.' ?></p>
        </div>
    </div>
</section>

<!-- ═══ QUIZ ═══ -->
<section class="quiz-section" id="quiz">
    <h2><?= $lang==='fr' ? 'Qui est votre couple ?' : 'Who is your couple?' ?></h2>
    <p class="quiz-sub"><?= $lang==='fr' ? '10 questions pour découvrir sa personnalité' : '10 questions to discover its personality' ?></p>

    <div class="quiz-progress" id="quizProgress"></div>

    <div id="quizContainer">
        <!-- Questions loaded by JS -->
    </div>

    <div class="quiz-result" id="quizResult">
        <div class="result-emoji" id="resultEmoji"></div>
        <div class="result-type" id="resultType"></div>
        <p class="result-desc" id="resultDesc"></p>
        <div class="result-gauges" id="resultGauges"></div>
        <a href="signup.php" class="result-cta" id="resultCta"><?= $lang==='fr' ? 'Donner vie à votre couple' : 'Bring your couple to life' ?></a>
    </div>
</section>

<footer class="landing-footer">
    <p>Natacha — <?= $lang==='fr' ? 'L\'app qui donne vie à votre couple' : 'The app that brings your couple to life' ?></p>
</footer>

<script>
const LANG = '<?= $lang ?>';

const QUESTIONS = LANG === 'fr' ? [
    {
        q: "Un samedi soir idéal ensemble, c'est...",
        opts: [
            "Explorer un endroit qu'on ne connaît pas",
            "Un dîner aux chandelles à la maison",
            "Un film sous la couette, juste nous deux",
            "Improviser quelque chose de complètement fou"
        ],
        // adventure, tenderness, complicity, surprise
        weights: [
            {adventure:3,surprise:1},
            {tenderness:3,communication:1},
            {complicity:3,tenderness:1},
            {surprise:3,adventure:1}
        ]
    },
    {
        q: "Quand quelque chose ne va pas entre vous...",
        opts: [
            "On en parle tout de suite, même si c'est dur",
            "On prend du recul, puis on revient plus calmes",
            "On se fait un câlin, les mots viendront après",
            "On change d'air, une sortie pour décompresser"
        ],
        weights: [
            {communication:3,complicity:1},
            {communication:2,complicity:2},
            {tenderness:3,communication:1},
            {adventure:2,surprise:2}
        ]
    },
    {
        q: "Le cadeau qui vous ferait le plus plaisir...",
        opts: [
            "Un voyage surprise",
            "Une lettre écrite à la main",
            "Un objet qui rappelle un souvenir commun",
            "Une expérience inédite à vivre ensemble"
        ],
        weights: [
            {surprise:3,adventure:1},
            {tenderness:3,communication:1},
            {complicity:3,tenderness:1},
            {adventure:3,surprise:1}
        ]
    },
    {
        q: "Ce qui vous rend le plus forts ensemble...",
        opts: [
            "On peut tout se dire, sans filtre",
            "On se comprend sans parler",
            "On ose tout essayer, ensemble",
            "On se surprend encore, même après du temps"
        ],
        weights: [
            {communication:3,complicity:1},
            {complicity:3,tenderness:1},
            {adventure:3,complicity:1},
            {surprise:3,tenderness:1}
        ]
    },
    {
        q: "Votre plus beau souvenir ensemble ressemble à...",
        opts: [
            "Un road trip ou une aventure improvisée",
            "Un moment calme, juste nous, hors du temps",
            "Un fou rire qu'on ne peut pas expliquer",
            "Une conversation profonde qui a tout changé"
        ],
        weights: [
            {adventure:3,surprise:1},
            {tenderness:3,complicity:1},
            {complicity:3,surprise:1},
            {communication:3,tenderness:1}
        ]
    },
    {
        q: "Quand vous imaginez votre couple dans 5 ans...",
        opts: [
            "On a exploré le monde ensemble",
            "On a construit un cocon solide et doux",
            "On se surprend encore chaque jour",
            "On se connaît par cœur et c'est magique"
        ],
        weights: [
            {adventure:3,surprise:1},
            {tenderness:3,communication:1},
            {surprise:3,adventure:1},
            {complicity:3,communication:1}
        ]
    },
    {
        q: "Votre couple a besoin de...",
        opts: [
            "Nouveauté régulière pour ne pas s'ennuyer",
            "Rituels et petites attentions au quotidien",
            "Moments de complicité exclusive",
            "Discussions profondes et honnêtes"
        ],
        weights: [
            {adventure:2,surprise:2},
            {tenderness:3,complicity:1},
            {complicity:3,tenderness:1},
            {communication:3,complicity:1}
        ]
    },
    {
        q: "Si votre couple était un animal...",
        opts: [
            "Un loup — libre, loyal, aventurier",
            "Un cygne — élégant, fidèle, romantique",
            "Un dauphin — joueur, intelligent, complice",
            "Un phénix — intense, renaissant, surprenant"
        ],
        weights: [
            {adventure:3,complicity:1},
            {tenderness:3,communication:1},
            {complicity:3,adventure:1},
            {surprise:3,tenderness:1}
        ]
    },
    {
        q: "Le pire ennemi de votre couple serait...",
        opts: [
            "La routine et l'ennui",
            "Le manque de communication",
            "La distance émotionnelle",
            "La perte de spontanéité"
        ],
        weights: [
            {adventure:2,surprise:2},
            {communication:3,tenderness:1},
            {tenderness:2,complicity:2},
            {surprise:3,adventure:1}
        ]
    },
    {
        q: "En un mot, votre couple c'est...",
        opts: [
            "L'aventure",
            "La douceur",
            "La complicité",
            "L'inattendu"
        ],
        weights: [
            {adventure:4},
            {tenderness:4},
            {complicity:4},
            {surprise:4}
        ]
    }
] : [
    {
        q: "An ideal Saturday evening together is...",
        opts: [
            "Exploring somewhere we've never been",
            "A candlelit dinner at home",
            "A movie under the covers, just the two of us",
            "Improvising something completely wild"
        ],
        weights: [
            {adventure:3,surprise:1},
            {tenderness:3,communication:1},
            {complicity:3,tenderness:1},
            {surprise:3,adventure:1}
        ]
    },
    {
        q: "When something's wrong between you...",
        opts: [
            "We talk about it right away, even if it's hard",
            "We step back, then come back calmer",
            "We hug first, words will come later",
            "We go out, change the scenery to decompress"
        ],
        weights: [
            {communication:3,complicity:1},
            {communication:2,complicity:2},
            {tenderness:3,communication:1},
            {adventure:2,surprise:2}
        ]
    },
    {
        q: "The gift that would make you happiest...",
        opts: [
            "A surprise trip",
            "A handwritten letter",
            "An object that recalls a shared memory",
            "A brand new experience to live together"
        ],
        weights: [
            {surprise:3,adventure:1},
            {tenderness:3,communication:1},
            {complicity:3,tenderness:1},
            {adventure:3,surprise:1}
        ]
    },
    {
        q: "What makes you strongest together...",
        opts: [
            "We can tell each other anything, unfiltered",
            "We understand each other without speaking",
            "We dare to try everything, together",
            "We still surprise each other, even after time"
        ],
        weights: [
            {communication:3,complicity:1},
            {complicity:3,tenderness:1},
            {adventure:3,complicity:1},
            {surprise:3,tenderness:1}
        ]
    },
    {
        q: "Your most beautiful memory together looks like...",
        opts: [
            "A road trip or improvised adventure",
            "A quiet moment, just us, outside of time",
            "An inside joke no one else gets",
            "A deep conversation that changed everything"
        ],
        weights: [
            {adventure:3,surprise:1},
            {tenderness:3,complicity:1},
            {complicity:3,surprise:1},
            {communication:3,tenderness:1}
        ]
    },
    {
        q: "When you imagine your couple in 5 years...",
        opts: [
            "We've explored the world together",
            "We've built a solid, warm cocoon",
            "We still surprise each other every day",
            "We know each other by heart and it's magical"
        ],
        weights: [
            {adventure:3,surprise:1},
            {tenderness:3,communication:1},
            {surprise:3,adventure:1},
            {complicity:3,communication:1}
        ]
    },
    {
        q: "Your couple needs...",
        opts: [
            "Regular novelty to avoid boredom",
            "Daily rituals and small attentions",
            "Moments of exclusive complicity",
            "Deep and honest conversations"
        ],
        weights: [
            {adventure:2,surprise:2},
            {tenderness:3,complicity:1},
            {complicity:3,tenderness:1},
            {communication:3,complicity:1}
        ]
    },
    {
        q: "If your couple was an animal...",
        opts: [
            "A wolf — free, loyal, adventurous",
            "A swan — elegant, faithful, romantic",
            "A dolphin — playful, smart, connected",
            "A phoenix — intense, reborn, surprising"
        ],
        weights: [
            {adventure:3,complicity:1},
            {tenderness:3,communication:1},
            {complicity:3,adventure:1},
            {surprise:3,tenderness:1}
        ]
    },
    {
        q: "Your couple's worst enemy would be...",
        opts: [
            "Routine and boredom",
            "Lack of communication",
            "Emotional distance",
            "Loss of spontaneity"
        ],
        weights: [
            {adventure:2,surprise:2},
            {communication:3,tenderness:1},
            {tenderness:2,complicity:2},
            {surprise:3,adventure:1}
        ]
    },
    {
        q: "In one word, your couple is...",
        opts: [
            "Adventure",
            "Sweetness",
            "Complicity",
            "The unexpected"
        ],
        weights: [
            {adventure:4},
            {tenderness:4},
            {complicity:4},
            {surprise:4}
        ]
    }
];

const PERSONALITIES = {
    adventurer: {
        name: LANG==='fr' ? 'Aventurier Passionné' : 'Passionate Adventurer',
        emoji: '🧭',
        desc: LANG==='fr'
            ? 'Votre couple vit d\'expériences et de découvertes. Vous avez besoin de nouveauté pour vibrer ensemble. Le monde est votre terrain de jeu.'
            : 'Your couple thrives on experiences and discoveries. You need novelty to feel alive together. The world is your playground.'
    },
    romantic: {
        name: LANG==='fr' ? 'Romantique Rêveur' : 'Dreamy Romantic',
        emoji: '🌹',
        desc: LANG==='fr'
            ? 'Votre couple est fait de douceur et d\'attentions. Les petits gestes comptent plus que les grandes aventures. Votre amour se construit dans l\'intime.'
            : 'Your couple is built on sweetness and attention. Small gestures matter more than grand adventures. Your love is built in intimacy.'
    },
    complice: {
        name: LANG==='fr' ? 'Complice Fusionnel' : 'Soulmate Connection',
        emoji: '🔗',
        desc: LANG==='fr'
            ? 'Votre couple fonctionne comme un seul être. Vous vous comprenez sans parler, vous vibrez ensemble. Votre connexion est rare et précieuse.'
            : 'Your couple functions as one being. You understand each other without words. Your connection is rare and precious.'
    },
    creative: {
        name: LANG==='fr' ? 'Créatif Électrique' : 'Electric Creative',
        emoji: '⚡',
        desc: LANG==='fr'
            ? 'Votre couple est imprévisible et stimulant. Vous vous surprenez, vous vous challengez. L\'ennui est votre pire ennemi.'
            : 'Your couple is unpredictable and stimulating. You surprise and challenge each other. Boredom is your worst enemy.'
    },
    sage: {
        name: LANG==='fr' ? 'Sage Profond' : 'Deep Sage',
        emoji: '🧘',
        desc: LANG==='fr'
            ? 'Votre couple est ancré et réfléchi. Vous construisez sur des bases solides avec patience et sagesse. Votre force, c\'est le dialogue.'
            : 'Your couple is grounded and thoughtful. You build on solid foundations with patience and wisdom. Your strength is dialogue.'
    }
};

const GAUGE_LABELS = {
    communication: LANG==='fr' ? 'Communication' : 'Communication',
    adventure:     LANG==='fr' ? 'Aventure' : 'Adventure',
    tenderness:    LANG==='fr' ? 'Tendresse' : 'Tenderness',
    surprise:      LANG==='fr' ? 'Surprise' : 'Surprise',
    complicity:    LANG==='fr' ? 'Complicité' : 'Complicity'
};

let currentQ = 0;
let answers = [];
let scores = {communication:0, adventure:0, tenderness:0, surprise:0, complicity:0};

function init() {
    // Build progress dots
    const prog = document.getElementById('quizProgress');
    for(let i = 0; i < QUESTIONS.length; i++) {
        const d = document.createElement('div');
        d.className = 'dot' + (i === 0 ? ' active' : '');
        prog.appendChild(d);
    }
    showQuestion(0);
}

function showQuestion(idx) {
    const container = document.getElementById('quizContainer');
    const q = QUESTIONS[idx];
    container.innerHTML = `
        <div class="quiz-question">
            <div class="q-number">${LANG==='fr'?'Question':'Question'} ${idx+1}/${QUESTIONS.length}</div>
            <div class="q-text">${q.q}</div>
            <div class="q-options">
                ${q.opts.map((opt, i) => `<button class="q-opt" onclick="selectAnswer(${idx},${i})">${opt}</button>`).join('')}
            </div>
        </div>
    `;
}

function selectAnswer(qIdx, aIdx) {
    const q = QUESTIONS[qIdx];
    const w = q.weights[aIdx];

    // Add weights to scores
    for(const [key, val] of Object.entries(w)) {
        scores[key] = (scores[key]||0) + val;
    }
    answers.push(aIdx);

    // Update progress
    const dots = document.querySelectorAll('.quiz-progress .dot');
    dots[qIdx].classList.remove('active');
    dots[qIdx].classList.add('done');

    if(qIdx + 1 < QUESTIONS.length) {
        currentQ = qIdx + 1;
        dots[currentQ].classList.add('active');
        showQuestion(currentQ);
    } else {
        showResult();
    }
}

function showResult() {
    document.getElementById('quizContainer').style.display = 'none';
    const result = document.getElementById('quizResult');
    result.style.display = 'block';

    // Normalize scores to 0-100
    const maxPossible = QUESTIONS.length * 4; // max weight per gauge
    const normalized = {};
    for(const [key, val] of Object.entries(scores)) {
        normalized[key] = Math.min(100, Math.round((val / maxPossible) * 100));
    }

    // Determine personality
    const sorted = Object.entries(scores).sort((a,b) => b[1] - a[1]);
    const top = sorted[0][0];
    let personality;
    if(top === 'adventure') personality = 'adventurer';
    else if(top === 'tenderness') personality = 'romantic';
    else if(top === 'complicity') personality = 'complice';
    else if(top === 'surprise') personality = 'creative';
    else personality = 'sage';

    const p = PERSONALITIES[personality];
    document.getElementById('resultEmoji').textContent = p.emoji;
    document.getElementById('resultType').textContent = p.name;
    document.getElementById('resultDesc').textContent = p.desc;

    // Build gauges
    const gaugesDiv = document.getElementById('resultGauges');
    gaugesDiv.innerHTML = '';
    for(const [key, label] of Object.entries(GAUGE_LABELS)) {
        const val = normalized[key] || 0;
        gaugesDiv.innerHTML += `
            <div class="gauge-row">
                <span class="gauge-label">${label}</span>
                <div class="gauge-bar"><div class="gauge-fill" style="width:0%" data-target="${val}"></div></div>
                <span class="gauge-val">${val}%</span>
            </div>
        `;
    }

    // Animate gauges
    setTimeout(() => {
        document.querySelectorAll('.gauge-fill').forEach(el => {
            el.style.width = el.dataset.target + '%';
        });
    }, 200);

    // Store in session for signup
    const resultCta = document.getElementById('resultCta');
    resultCta.href = `signup.php?personality=${personality}&scores=${encodeURIComponent(JSON.stringify(normalized))}&answers=${encodeURIComponent(JSON.stringify(answers))}`;
}

init();
</script>
</body>
</html>
