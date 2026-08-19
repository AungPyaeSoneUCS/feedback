<?php
/**
 * Shared Typewriter University Title Component
 * Include this in any dashboard to show the animated university name
 * with language toggle (ENG / မြန်မာ).
 *
 * Requires: $LANG, $_SESSION['lang'] already loaded via functions.php
 */
$currentLang = $_SESSION['lang'] ?? 'en';
$universityEn = 'UNIVERSITY OF COMPUTER<br>STUDIES (HINTHADA)';
$universityMm = 'ကွန်ပျူတာတက္ကသိုလ် (ဟင်္သာတ)';
$portalLabel = $LANG['student_feedback_system'] ?? 'Student Feedback Information System';
?>

<!-- Typewriter University Title Box -->
<div class="typewriter-box rounded-2xl p-6 md:p-8 mb-6 text-center relative">
    <!-- Language Toggle -->
    <div class="flex items-center justify-center gap-2 mb-5">
        <button type="button" onclick="typewriterSwitchLang('en')"
            class="lang-toggle-btn px-4 py-1.5 rounded-full text-xs font-bold tracking-wider border
            <?= $currentLang === 'en'
                ? 'active border-indigo-400/50 text-indigo-300 bg-indigo-500/10'
                : 'border-white/10 text-white/40 hover:text-white/70 hover:border-white/20' ?>">
            ENG
        </button>
        <button type="button" onclick="typewriterSwitchLang('mm')"
            class="lang-toggle-btn px-4 py-1.5 rounded-full text-xs font-bold tracking-wider border
            <?= $currentLang === 'mm'
                ? 'active border-indigo-400/50 text-indigo-300 bg-indigo-500/10'
                : 'border-white/10 text-white/40 hover:text-white/70 hover:border-white/20' ?>">
            မြန်မာ
        </button>
    </div>

    <!-- Typewriter Text -->
    <div class="relative z-10">
        <h1 id="typewriter-title"
            class="typewriter-text text-xl md:text-2xl lg:text-3xl font-black text-white tracking-wide">
            <span id="tw-text"></span>
        </h1>
        <p id="typewriter-subtitle"
           class="typewriter-subtitle text-sm md:text-base text-indigo-300/70 mt-3 font-medium tracking-wide">
            <?= e($portalLabel) ?>
        </p>
    </div>

    <!-- Decorative dots -->
    <div class="absolute top-3 left-3 flex gap-1.5">
        <span class="w-2 h-2 rounded-full bg-red-400/60"></span>
        <span class="w-2 h-2 rounded-full bg-amber-400/60"></span>
        <span class="w-2 h-2 rounded-full bg-emerald-400/60"></span>
    </div>
</div>

<script>
(function() {
    const TEXTS = {
        en: <?= json_encode($universityEn, JSON_UNESCAPED_UNICODE) ?>,
        mm: <?= json_encode($universityMm, JSON_UNESCAPED_UNICODE) ?>
    };

    const TYPE_SPEED_EN = 65;
    const TYPE_SPEED_MM = 90;
    const DELETE_SPEED  = 30;
    const PAUSE_AFTER   = 2200;
    const PAUSE_DELETE  = 600;

    let currentLang = '<?= $currentLang ?>';
    let typingTimer = null;
    let isDeleting  = false;
    let charIndex   = 0;

    const textEl    = document.getElementById('tw-text');
    const subtitleEl = document.getElementById('typewriter-subtitle');

    /**
     * Segment a string into grapheme clusters.
     * Uses Intl.Segmenter (ES2022) for proper Myanmar combining characters.
     * Falls back to Array.from() which handles Unicode code points.
     */
    function segmentGraphemes(str) {
        if (typeof Intl !== 'undefined' && Intl.Segmenter) {
            const segmenter = new Intl.Segmenter('my', { granularity: 'grapheme' });
            return Array.from(segmenter.segment(str), s => s.segment);
        }
        return Array.from(str);
    }

    function getFullText() {
        return TEXTS[currentLang] || TEXTS.en;
    }

    function getSpeed() {
        return currentLang === 'mm' ? TYPE_SPEED_MM : TYPE_SPEED_EN;
    }

    function renderText(graphemes, count) {
        var raw = graphemes.slice(0, count).join('');
        var parts = raw.split(/<br>/gi);
        var safe = parts.map(function(p) {
            return p.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        });
        textEl.innerHTML = safe.join('<br>');
    }

    function typeStep() {
        const fullText = getFullText();
        const graphemes = segmentGraphemes(fullText);
        const totalChars = graphemes.length;

        if (!isDeleting) {
            /* ── Typing ── */
            charIndex++;
            renderText(graphemes, charIndex);

            if (charIndex >= totalChars) {
                /* Finished typing — pause, then start deleting */
                subtitleEl.classList.add('visible');
                typingTimer = setTimeout(function() {
                    subtitleEl.classList.remove('visible');
                    typingTimer = setTimeout(function() {
                        isDeleting = true;
                        typeStep();
                    }, PAUSE_DELETE);
                }, PAUSE_AFTER);
                return;
            }
            typingTimer = setTimeout(typeStep, getSpeed());

        } else {
            /* ── Deleting ── */
            charIndex--;
            renderText(graphemes, charIndex);

            if (charIndex <= 0) {
                /* Finished deleting — brief pause, then switch language and retype */
                isDeleting = false;
                typingTimer = setTimeout(function() {
                    typeStep();
                }, 400);
                return;
            }
            typingTimer = setTimeout(typeStep, DELETE_SPEED);
        }
    }

    function startTyping() {
        if (typingTimer) clearTimeout(typingTimer);
        charIndex = 0;
        isDeleting = false;
        textEl.innerHTML = '';
        subtitleEl.classList.remove('visible');
        typeStep();
    }

    /* ── Language switch via server reload (matches existing ?lang= mechanism) ── */
    window.typewriterSwitchLang = function(lang) {
        if (lang === currentLang) return;
        window.location.href = '?lang=' + lang;
    };

    /* ── Boot ── */
    startTyping();
})();
</script>
