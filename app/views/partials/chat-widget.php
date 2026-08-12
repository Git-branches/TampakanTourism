<?php
/**
 * =============================================================================
 *  Tampakan Tourism Assistant — widget                              Feature 4
 * -----------------------------------------------------------------------------
 *  A launcher fixed to the corner of the viewport, so it is reachable from
 *  every section of the page without following the reader down it. The panel is
 *  a dialog rather than an inline block for the same reason: someone halfway
 *  through the destinations grid should be able to ask what time a place opens
 *  without losing their place in the grid.
 *
 *  Rendered server-side and inert. Nothing here needs JavaScript to exist — the
 *  markup ships hidden and chat.js reveals it, so a script that fails to load
 *  leaves no dead button rather than a button that does nothing.
 * =============================================================================
 */

if (!defined('TOURSYNC')) {
    exit('Direct access is not permitted.');
}

use App\Core\Chatbot;

/**
 * The opening quick actions.
 *
 * Two of the six deliberately lead somewhere Gemini is not involved at all —
 * destinations and FAQs are answered from the database — so the cheapest paths
 * are also the most prominent ones.
 */
$chatActions = [
    ['icon' => '🏞️', 'label' => 'Explore Destinations',   'ask' => 'What destinations can I visit?'],
    ['icon' => '🌿', 'label' => 'Nature Destinations',    'ask' => 'Which destinations are best for nature lovers?'],
    ['icon' => '👨‍👩‍👧', 'label' => 'Family-Friendly',        'ask' => 'Which destinations are good for families?'],
    ['icon' => '💰', 'label' => 'Plan My Budget',         'ask' => 'I have ₱3,000. What can I do in Tampakan?'],
    ['icon' => '📍', 'label' => 'Find a Destination',     'ask' => 'Where are the destinations located?'],
    ['icon' => '❓', 'label' => 'Tourism FAQs',            'ask' => 'What are the entrance fees and opening hours?'],
];
?>

<!-- Hidden until chat.js runs: a launcher that does nothing is worse than none. -->
<button type="button" class="chat-launcher" id="chatLauncher" hidden
        aria-haspopup="dialog" aria-expanded="false" aria-controls="chatPanel">
    <i class="fa-solid fa-comment-dots" aria-hidden="true"></i>
    <span class="chat-launcher__label">Ask Tampakan Tourism</span>
</button>

<section class="chat-panel" id="chatPanel" role="dialog" aria-modal="false"
         aria-labelledby="chatPanelTitle" hidden>

    <header class="chat-panel__head">
        <div class="chat-panel__title">
            <h2 id="chatPanelTitle">Tampakan Tourism Assistant</h2>
            <p>Answers from the Municipal Tourism Office records</p>
        </div>
        <div class="chat-panel__tools">
            <button type="button" class="chat-icon-btn" id="chatClear" title="Clear this conversation"
                    aria-label="Clear this conversation">
                <i class="fa-solid fa-eraser" aria-hidden="true"></i>
            </button>
            <button type="button" class="chat-icon-btn" id="chatClose" aria-label="Close the assistant">
                <i class="fa-solid fa-xmark" aria-hidden="true"></i>
            </button>
        </div>
    </header>

    <!-- aria-live so a screen reader hears each answer as it arrives; polite
         rather than assertive, because an answer should not interrupt the
         reader mid-sentence. -->
    <div class="chat-log" id="chatLog" role="log" aria-live="polite" aria-atomic="false"></div>

    <!-- The opening screen, rebuilt by chat.js after the conversation is
         cleared. Kept in the markup so it is present before any script runs. -->
    <template id="chatWelcome">
        <div class="chat-msg chat-msg--bot">
            <div class="chat-bubble">
                <p>
                    Hi! I&rsquo;m the Tampakan Tourism Assistant. I can help you discover destinations,
                    find tourism information, and plan your trip &mdash; in English, Filipino, or Cebuano.
                </p>
            </div>
        </div>
        <div class="chat-actions">
            <?php foreach ($chatActions as $a): ?>
                <button type="button" class="chat-action" data-chat-suggest data-ask="<?= e($a['ask']) ?>">
                    <span aria-hidden="true"><?= $a['icon'] ?></span> <?= e($a['label']) ?>
                </button>
            <?php endforeach; ?>
        </div>
    </template>

    <div class="chat-suggest" id="chatSuggest"></div>

    <form class="chat-form" id="chatForm" autocomplete="off">
        <label class="visually-hidden" for="chatInput">Your question</label>
        <input type="text" id="chatInput" name="q"
               maxlength="<?= (int) Chatbot::MAX_QUESTION_LENGTH ?>"
               placeholder="Ask about destinations, fees, or your budget&hellip;" required>
        <button type="submit" id="chatSend" aria-label="Send">
            <i class="fa-solid fa-paper-plane" aria-hidden="true"></i>
        </button>
    </form>

    <!-- Said plainly, once, and kept to a single line. A visitor is entitled to
         know they are not talking to a person and that nothing they type is
         kept; they are not entitled to lose half the panel to the notice. -->
    <p class="chat-foot">
        Automated &middot; from published records &middot; nothing you type is stored
    </p>
</section>
