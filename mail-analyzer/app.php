<?php
/**
 * Mail Analyzer - Local PHP mail file search with synonym expansion.
 *
 * Run with: php -S localhost:8080 app.php
 * Then open http://localhost:8080 in your browser.
 */

require_once __DIR__ . '/SearchEngine.php';

// ── Handle API requests ──────────────────────────────────────────────

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

header('Content-Type: text/html; charset=utf-8');

if ($action !== '') {
    header('Content-Type: application/json; charset=utf-8');
    handleApi($action);
    exit;
}

// ── Serve the UI ─────────────────────────────────────────────────────

renderUI();
exit;

// =====================================================================
// API handlers
// =====================================================================

function handleApi(string $action): void
{
    switch ($action) {

        case 'search':
            $dir  = $_GET['dir'] ?? '';
            $file = $_GET['file'] ?? '';

            if (empty($dir) && empty($file)) {
                jsonResponse(['error' => 'Provide a dir or file parameter']);
                return;
            }

            $engine = new SearchEngine();

            if (!empty($dir)) {
                if (!is_dir($dir)) {
                    jsonResponse(['error' => "Directory not found: $dir"]);
                    return;
                }
                $engine->loadDirectory($dir);
            }
            if (!empty($file)) {
                if (!file_exists($file)) {
                    jsonResponse(['error' => "File not found: $file"]);
                    return;
                }
                $engine->loadFile($file);
            }

            $criteria = [
                'query'         => $_GET['q'] ?? '',
                'useSynonyms'   => ($_GET['synonyms'] ?? '0') === '1',
                'fields'        => isset($_GET['fields']) ? explode(',', $_GET['fields']) : ['from', 'to', 'cc', 'subject', 'body'],
                'dateFrom'      => $_GET['dateFrom'] ?? '',
                'dateTo'        => $_GET['dateTo'] ?? '',
                'caseSensitive' => ($_GET['caseSensitive'] ?? '0') === '1',
                'regex'         => ($_GET['regex'] ?? '0') === '1',
                'wholeWord'     => ($_GET['wholeWord'] ?? '0') === '1',
            ];

            $results = $engine->search($criteria);
            $results['stats'] = $engine->getStats();

            // Strip raw headers from response to reduce payload
            foreach ($results['results'] as &$r) {
                unset($r['headers_raw']);
            }

            jsonResponse($results);
            break;

        case 'synonyms':
            $term = $_GET['term'] ?? '';
            $syn  = new SynonymEngine();
            jsonResponse([
                'term'     => $term,
                'synonyms' => $syn->getSynonyms($term),
                'expanded' => $syn->expandSearch($term),
            ]);
            break;

        case 'stats':
            $dir = $_GET['dir'] ?? '';
            if (empty($dir) || !is_dir($dir)) {
                jsonResponse(['error' => 'Provide a valid dir parameter']);
                return;
            }
            $engine = new SearchEngine();
            $engine->loadDirectory($dir);
            jsonResponse($engine->getStats());
            break;

        case 'custom_synonyms':
            $syn = new SynonymEngine();
            if ($method === 'GET' || $_SERVER['REQUEST_METHOD'] === 'GET') {
                jsonResponse(['groups' => $syn->getCustomGroups()]);
            }
            break;

        case 'add_custom_synonym':
            $input = json_decode(file_get_contents('php://input'), true);
            $words = $input['words'] ?? [];
            $syn   = new SynonymEngine();
            $syn->addCustomGroup($words);
            jsonResponse(['ok' => true, 'groups' => $syn->getCustomGroups()]);
            break;

        case 'remove_custom_synonym':
            $input = json_decode(file_get_contents('php://input'), true);
            $index = (int)($input['index'] ?? -1);
            $syn   = new SynonymEngine();
            $syn->removeCustomGroup($index);
            jsonResponse(['ok' => true, 'groups' => $syn->getCustomGroups()]);
            break;

        default:
            jsonResponse(['error' => "Unknown action: $action"]);
    }
}

function jsonResponse(array $data): void
{
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
}

// =====================================================================
// UI
// =====================================================================

function renderUI(): void
{
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Mail Analyzer &mdash; Local Email Search</title>
<style>
:root {
    --bg: #0f172a;
    --bg2: #1e293b;
    --bg3: #334155;
    --text: #e2e8f0;
    --text2: #94a3b8;
    --accent: #38bdf8;
    --accent2: #818cf8;
    --green: #4ade80;
    --red: #f87171;
    --orange: #fb923c;
    --border: #475569;
    --radius: 8px;
}
* { box-sizing: border-box; margin: 0; padding: 0; }
body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    background: var(--bg);
    color: var(--text);
    line-height: 1.5;
    min-height: 100vh;
}
a { color: var(--accent); text-decoration: none; }
a:hover { text-decoration: underline; }

.container { max-width: 1200px; margin: 0 auto; padding: 20px; }

header {
    background: var(--bg2);
    border-bottom: 1px solid var(--border);
    padding: 16px 20px;
    display: flex;
    align-items: center;
    gap: 16px;
}
header h1 { font-size: 1.4rem; color: var(--accent); }
header .subtitle { color: var(--text2); font-size: 0.85rem; }

/* Tabs */
.tabs { display: flex; gap: 0; border-bottom: 2px solid var(--border); margin-bottom: 20px; }
.tab {
    padding: 10px 20px;
    cursor: pointer;
    color: var(--text2);
    border-bottom: 2px solid transparent;
    margin-bottom: -2px;
    transition: all 0.2s;
    font-size: 0.9rem;
    background: none;
    border-top: none; border-left: none; border-right: none;
}
.tab:hover { color: var(--text); }
.tab.active { color: var(--accent); border-bottom-color: var(--accent); }

.tab-content { display: none; }
.tab-content.active { display: block; }

/* Forms */
.form-row { display: flex; gap: 12px; margin-bottom: 12px; flex-wrap: wrap; align-items: flex-end; }
.form-group { display: flex; flex-direction: column; gap: 4px; }
.form-group label { font-size: 0.8rem; color: var(--text2); font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; }
input[type="text"], input[type="date"], select {
    background: var(--bg);
    border: 1px solid var(--border);
    color: var(--text);
    padding: 8px 12px;
    border-radius: var(--radius);
    font-size: 0.9rem;
    outline: none;
    min-width: 200px;
}
input[type="text"]:focus, input[type="date"]:focus {
    border-color: var(--accent);
    box-shadow: 0 0 0 2px rgba(56, 189, 248, 0.2);
}
.checkbox-row {
    display: flex;
    gap: 16px;
    flex-wrap: wrap;
    align-items: center;
    padding: 8px 0;
}
.checkbox-row label {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 0.85rem;
    cursor: pointer;
    color: var(--text2);
}
.checkbox-row label:hover { color: var(--text); }
input[type="checkbox"] { accent-color: var(--accent); width: 16px; height: 16px; }
button {
    background: var(--accent);
    color: var(--bg);
    border: none;
    padding: 8px 20px;
    border-radius: var(--radius);
    font-size: 0.9rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
}
button:hover { opacity: 0.85; transform: translateY(-1px); }
button.secondary { background: var(--bg3); color: var(--text); }
button.danger { background: var(--red); }

/* Panels */
.panel {
    background: var(--bg2);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 16px;
    margin-bottom: 16px;
}
.panel h3 {
    font-size: 0.95rem;
    margin-bottom: 12px;
    color: var(--accent);
    display: flex;
    align-items: center;
    gap: 8px;
}

/* Stats bar */
.stats-bar {
    display: flex;
    gap: 20px;
    flex-wrap: wrap;
    padding: 12px 16px;
    background: var(--bg2);
    border-radius: var(--radius);
    border: 1px solid var(--border);
    margin-bottom: 16px;
    font-size: 0.85rem;
}
.stat { color: var(--text2); }
.stat strong { color: var(--accent); }

/* Results */
.result-card {
    background: var(--bg2);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    margin-bottom: 12px;
    overflow: hidden;
    transition: border-color 0.2s;
}
.result-card:hover { border-color: var(--accent); }
.result-header {
    padding: 12px 16px;
    cursor: pointer;
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 12px;
}
.result-header:hover { background: rgba(56, 189, 248, 0.05); }
.result-subject { font-weight: 600; font-size: 0.95rem; }
.result-meta { font-size: 0.8rem; color: var(--text2); display: flex; gap: 16px; flex-wrap: wrap; }
.result-body {
    display: none;
    padding: 0 16px 16px;
    border-top: 1px solid var(--border);
}
.result-body.open { display: block; padding-top: 12px; }
.result-body-text {
    white-space: pre-wrap;
    font-family: 'Courier New', monospace;
    font-size: 0.82rem;
    max-height: 400px;
    overflow-y: auto;
    background: var(--bg);
    padding: 12px;
    border-radius: var(--radius);
    line-height: 1.6;
}
.highlight { background: rgba(251, 146, 60, 0.35); color: var(--orange); padding: 1px 3px; border-radius: 3px; }
.match-badges { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 6px; }
.badge {
    font-size: 0.7rem;
    padding: 2px 8px;
    border-radius: 12px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}
.badge-field { background: rgba(129, 140, 248, 0.2); color: var(--accent2); }
.badge-term { background: rgba(74, 222, 128, 0.2); color: var(--green); }

/* Synonym tags */
.synonym-tags { display: flex; gap: 6px; flex-wrap: wrap; margin: 8px 0; }
.syn-tag {
    font-size: 0.8rem;
    padding: 4px 10px;
    background: var(--bg3);
    border-radius: 16px;
    color: var(--accent);
    border: 1px solid var(--border);
}

/* Custom synonym groups */
.syn-group {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 12px;
    background: var(--bg);
    border-radius: var(--radius);
    margin-bottom: 6px;
}
.syn-group .words { flex: 1; font-size: 0.85rem; }
.syn-group .words span {
    background: var(--bg3);
    padding: 2px 8px;
    border-radius: 12px;
    margin-right: 4px;
    font-size: 0.8rem;
}

.loading { text-align: center; color: var(--text2); padding: 40px; }
.loading::after {
    content: '';
    display: inline-block;
    width: 20px;
    height: 20px;
    border: 2px solid var(--border);
    border-top-color: var(--accent);
    border-radius: 50%;
    animation: spin 0.6s linear infinite;
    margin-left: 8px;
    vertical-align: middle;
}
@keyframes spin { to { transform: rotate(360deg); } }

.empty-state { text-align: center; color: var(--text2); padding: 60px 20px; }
.empty-state h2 { color: var(--text); margin-bottom: 8px; }

.field-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 4px; }

/* Responsive */
@media (max-width: 768px) {
    .form-row { flex-direction: column; }
    input[type="text"], input[type="date"] { min-width: unset; width: 100%; }
    .result-meta { flex-direction: column; gap: 4px; }
}
</style>
</head>
<body>

<header>
    <div>
        <h1>Mail Analyzer</h1>
        <div class="subtitle">Local email file search with synonym expansion</div>
    </div>
</header>

<div class="container">

<div class="tabs">
    <button class="tab active" onclick="switchTab('search')">Search</button>
    <button class="tab" onclick="switchTab('synonyms')">Synonyms</button>
    <button class="tab" onclick="switchTab('stats')">Stats</button>
</div>

<!-- ── SEARCH TAB ─────────────────────────────────────────── -->
<div id="tab-search" class="tab-content active">

<div class="panel">
    <h3>Search Configuration</h3>
    <div class="form-row">
        <div class="form-group" style="flex:2">
            <label>Mail Directory or File Path</label>
            <input type="text" id="mailPath" placeholder="/path/to/mail/folder or /path/to/file.eml" value="">
        </div>
        <div class="form-group" style="flex:2">
            <label>Search Query</label>
            <input type="text" id="searchQuery" placeholder="Enter search term or phrase..." value="">
        </div>
        <div class="form-group">
            <button onclick="doSearch()">Search</button>
        </div>
    </div>

    <div class="form-row">
        <div class="form-group">
            <label>Date From</label>
            <input type="date" id="dateFrom">
        </div>
        <div class="form-group">
            <label>Date To</label>
            <input type="date" id="dateTo">
        </div>
    </div>

    <div class="checkbox-row">
        <label><input type="checkbox" id="chkSynonyms"> Expand with Synonyms</label>
        <label><input type="checkbox" id="chkCaseSensitive"> Case Sensitive</label>
        <label><input type="checkbox" id="chkWholeWord"> Whole Word</label>
        <label><input type="checkbox" id="chkRegex"> Regex</label>
    </div>

    <div style="margin-top: 8px;">
        <label style="font-size:0.8rem; color:var(--text2); font-weight:600; text-transform:uppercase; letter-spacing:0.05em;">Search In Fields</label>
        <div class="field-grid checkbox-row" style="margin-top: 4px;">
            <label><input type="checkbox" class="fieldChk" value="from" checked> From</label>
            <label><input type="checkbox" class="fieldChk" value="to" checked> To</label>
            <label><input type="checkbox" class="fieldChk" value="cc" checked> CC</label>
            <label><input type="checkbox" class="fieldChk" value="bcc"> BCC</label>
            <label><input type="checkbox" class="fieldChk" value="subject" checked> Subject</label>
            <label><input type="checkbox" class="fieldChk" value="body" checked> Body</label>
            <label><input type="checkbox" class="fieldChk" value="attachments"> Attachments</label>
            <label><input type="checkbox" class="fieldChk" value="headers_raw"> Raw Headers</label>
        </div>
    </div>
</div>

<div id="synonymPreview" style="display:none;" class="panel">
    <h3>Synonym Expansion</h3>
    <div>Searching for these terms:</div>
    <div id="synonymTags" class="synonym-tags"></div>
</div>

<div id="resultsBar" class="stats-bar" style="display:none;"></div>
<div id="results"></div>

</div>

<!-- ── SYNONYMS TAB ───────────────────────────────────────── -->
<div id="tab-synonyms" class="tab-content">

<div class="panel">
    <h3>Synonym Lookup</h3>
    <div class="form-row">
        <div class="form-group" style="flex:1">
            <label>Look up synonyms for a word</label>
            <input type="text" id="synLookup" placeholder="Enter a word...">
        </div>
        <div class="form-group">
            <button onclick="lookupSynonym()">Look Up</button>
        </div>
    </div>
    <div id="synResults" style="margin-top: 12px;"></div>
</div>

<div class="panel">
    <h3>Custom Synonym Groups</h3>
    <p style="font-size:0.85rem; color:var(--text2); margin-bottom: 12px;">
        Add your own synonym groups. Words in the same group will be treated as synonyms of each other during search.
    </p>
    <div class="form-row">
        <div class="form-group" style="flex:1">
            <label>Words (comma-separated)</label>
            <input type="text" id="customSynInput" placeholder="word1, word2, word3, ...">
        </div>
        <div class="form-group">
            <button onclick="addCustomSynonym()">Add Group</button>
        </div>
    </div>
    <div id="customSynGroups" style="margin-top: 12px;"></div>
</div>

</div>

<!-- ── STATS TAB ──────────────────────────────────────────── -->
<div id="tab-stats" class="tab-content">

<div class="panel">
    <h3>Email Statistics</h3>
    <div class="form-row">
        <div class="form-group" style="flex:1">
            <label>Mail Directory Path</label>
            <input type="text" id="statsPath" placeholder="/path/to/mail/folder">
        </div>
        <div class="form-group">
            <button onclick="loadStats()">Load Stats</button>
        </div>
    </div>
    <div id="statsResults" style="margin-top: 12px;"></div>
</div>

</div>

</div>

<script>
// ── Tab switching ────────────────────────────────────────────
function switchTab(name) {
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
    document.getElementById('tab-' + name).classList.add('active');
    event.target.classList.add('active');
}

// ── Search ───────────────────────────────────────────────────
async function doSearch() {
    const path = document.getElementById('mailPath').value.trim();
    const query = document.getElementById('searchQuery').value.trim();
    const resultsDiv = document.getElementById('results');
    const barDiv = document.getElementById('resultsBar');
    const synDiv = document.getElementById('synonymPreview');

    if (!path) { alert('Please enter a mail directory or file path.'); return; }

    const fields = Array.from(document.querySelectorAll('.fieldChk:checked')).map(c => c.value);

    const params = new URLSearchParams({
        action: 'search',
        q: query,
        fields: fields.join(','),
        synonyms: document.getElementById('chkSynonyms').checked ? '1' : '0',
        caseSensitive: document.getElementById('chkCaseSensitive').checked ? '1' : '0',
        wholeWord: document.getElementById('chkWholeWord').checked ? '1' : '0',
        regex: document.getElementById('chkRegex').checked ? '1' : '0',
        dateFrom: document.getElementById('dateFrom').value,
        dateTo: document.getElementById('dateTo').value,
    });

    // Determine if path is a dir or file
    if (path.match(/\.(eml|mbox|msg|mst)$/i)) {
        params.set('file', path);
    } else {
        params.set('dir', path);
    }

    resultsDiv.innerHTML = '<div class="loading">Searching emails</div>';
    barDiv.style.display = 'none';
    synDiv.style.display = 'none';

    try {
        const resp = await fetch('?'+ params.toString());
        const data = await resp.json();

        if (data.error) {
            resultsDiv.innerHTML = '<div class="panel" style="color:var(--red);">' + escHtml(data.error) + '</div>';
            return;
        }

        // Show synonym expansion
        if (data.terms && data.terms.length > 1) {
            synDiv.style.display = 'block';
            document.getElementById('synonymTags').innerHTML = data.terms.map(t =>
                '<span class="syn-tag">' + escHtml(t) + '</span>'
            ).join('');
        }

        // Stats bar
        barDiv.style.display = 'flex';
        barDiv.innerHTML = `
            <span class="stat"><strong>${data.results.length}</strong> matches</span>
            <span class="stat"><strong>${data.totalEmails}</strong> emails scanned</span>
            <span class="stat"><strong>${data.terms ? data.terms.length : 0}</strong> search terms</span>
            <span class="stat"><strong>${data.stats ? data.stats.totalFiles : 0}</strong> files loaded</span>
        `;

        if (data.results.length === 0) {
            resultsDiv.innerHTML = '<div class="empty-state"><h2>No results found</h2><p>Try different search terms or enable synonym expansion.</p></div>';
            return;
        }

        resultsDiv.innerHTML = data.results.map((email, i) => renderResult(email, i, query)).join('');

    } catch (err) {
        resultsDiv.innerHTML = '<div class="panel" style="color:var(--red);">Error: ' + escHtml(err.message) + '</div>';
    }
}

function renderResult(email, index, query) {
    const mi = email._matchInfo || {};
    const matchedFields = (mi.matchedFields || []);
    const matchedTerms = (mi.matchedTerms || []);

    const subject = email.subject || '(No Subject)';
    const from = email.from || '(Unknown Sender)';
    const to = email.to || '';
    const date = email.date || '';
    const file = email.file || '';

    let bodyPreview = (email.body || '').substring(0, 300);
    if (query) bodyPreview = highlightText(bodyPreview, matchedTerms);

    const badges = [
        ...matchedFields.map(f => `<span class="badge badge-field">${escHtml(f)}</span>`),
        ...matchedTerms.map(t => `<span class="badge badge-term">${escHtml(t)}</span>`)
    ].join('');

    const highlightedSubject = query ? highlightText(escHtml(subject), matchedTerms) : escHtml(subject);

    return `
    <div class="result-card" id="result-${index}">
        <div class="result-header" onclick="toggleResult(${index})">
            <div>
                <div class="result-subject">${highlightedSubject}</div>
                <div class="result-meta">
                    <span><strong>From:</strong> ${escHtml(from)}</span>
                    <span><strong>To:</strong> ${escHtml(to)}</span>
                    ${date ? `<span><strong>Date:</strong> ${escHtml(date)}</span>` : ''}
                </div>
                ${badges ? `<div class="match-badges">${badges}</div>` : ''}
            </div>
        </div>
        <div class="result-body" id="result-body-${index}">
            <div style="font-size:0.75rem; color:var(--text2); margin-bottom:8px;">
                <strong>File:</strong> ${escHtml(file)}
                ${email.attachments && email.attachments.length ? `<br><strong>Attachments:</strong> ${email.attachments.map(a => escHtml(a)).join(', ')}` : ''}
            </div>
            <div class="result-body-text">${query ? highlightText(escHtml(email.body || ''), matchedTerms) : escHtml(email.body || '(No body)')}</div>
        </div>
    </div>`;
}

function toggleResult(index) {
    const body = document.getElementById('result-body-' + index);
    body.classList.toggle('open');
}

function highlightText(text, terms) {
    if (!terms || terms.length === 0) return text;
    // Sort by length desc so longer phrases match first
    const sorted = [...terms].sort((a, b) => b.length - a.length);
    const escaped = sorted.map(t => t.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'));
    const regex = new RegExp('(' + escaped.join('|') + ')', 'gi');
    return text.replace(regex, '<span class="highlight">$1</span>');
}

// ── Synonym lookup ───────────────────────────────────────────
async function lookupSynonym() {
    const term = document.getElementById('synLookup').value.trim();
    const div = document.getElementById('synResults');
    if (!term) return;

    const resp = await fetch('?action=synonyms&term=' + encodeURIComponent(term));
    const data = await resp.json();

    if (data.synonyms.length === 0) {
        div.innerHTML = '<p style="color:var(--text2);">No synonyms found for "' + escHtml(term) + '". You can add custom synonym groups below.</p>';
        return;
    }

    div.innerHTML = `
        <p style="margin-bottom:8px;"><strong>Synonyms for "${escHtml(term)}":</strong></p>
        <div class="synonym-tags">
            ${data.synonyms.map(s => `<span class="syn-tag">${escHtml(s)}</span>`).join('')}
        </div>
        ${data.expanded.length > data.synonyms.length + 1 ? `
            <p style="margin-top:12px; margin-bottom:8px;"><strong>Full expansion (including sub-words):</strong></p>
            <div class="synonym-tags">
                ${data.expanded.map(s => `<span class="syn-tag">${escHtml(s)}</span>`).join('')}
            </div>
        ` : ''}
    `;
}

// ── Custom synonyms ─────────────────────────────────────────
async function addCustomSynonym() {
    const input = document.getElementById('customSynInput');
    const words = input.value.split(',').map(w => w.trim()).filter(w => w);
    if (words.length < 2) { alert('Enter at least 2 words separated by commas.'); return; }

    await fetch('?action=add_custom_synonym', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({words})
    });
    input.value = '';
    loadCustomSynonyms();
}

async function removeCustomSynonym(index) {
    await fetch('?action=remove_custom_synonym', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({index})
    });
    loadCustomSynonyms();
}

async function loadCustomSynonyms() {
    const resp = await fetch('?action=custom_synonyms');
    const data = await resp.json();
    const div = document.getElementById('customSynGroups');

    if (!data.groups || data.groups.length === 0) {
        div.innerHTML = '<p style="color:var(--text2); font-size:0.85rem;">No custom synonym groups yet.</p>';
        return;
    }

    div.innerHTML = data.groups.map((group, i) => `
        <div class="syn-group">
            <div class="words">${group.map(w => `<span>${escHtml(w)}</span>`).join('')}</div>
            <button class="danger" style="padding:4px 10px; font-size:0.75rem;" onclick="removeCustomSynonym(${i})">Remove</button>
        </div>
    `).join('');
}

// ── Stats ────────────────────────────────────────────────────
async function loadStats() {
    const dir = document.getElementById('statsPath').value.trim();
    const div = document.getElementById('statsResults');
    if (!dir) { alert('Enter a directory path.'); return; }

    div.innerHTML = '<div class="loading">Loading statistics</div>';

    try {
        const resp = await fetch('?action=stats&dir=' + encodeURIComponent(dir));
        const data = await resp.json();

        if (data.error) {
            div.innerHTML = '<div style="color:var(--red);">' + escHtml(data.error) + '</div>';
            return;
        }

        const senderList = Object.entries(data.senders || {}).slice(0, 20);

        div.innerHTML = `
            <div class="stats-bar">
                <span class="stat"><strong>${data.totalEmails}</strong> emails</span>
                <span class="stat"><strong>${data.totalFiles}</strong> files</span>
                <span class="stat"><strong>${data.withAttachments}</strong> with attachments</span>
                <span class="stat"><strong>${Object.keys(data.senders || {}).length}</strong> unique senders</span>
            </div>
            ${data.dateRange.earliest ? `
                <div class="panel">
                    <h3>Date Range</h3>
                    <p>${escHtml(data.dateRange.earliest)} &mdash; ${escHtml(data.dateRange.latest)}</p>
                </div>
            ` : ''}
            ${senderList.length ? `
                <div class="panel">
                    <h3>Top Senders</h3>
                    ${senderList.map(([sender, count]) => `
                        <div style="display:flex; justify-content:space-between; padding:4px 0; border-bottom:1px solid var(--border); font-size:0.85rem;">
                            <span>${escHtml(sender)}</span>
                            <strong>${count}</strong>
                        </div>
                    `).join('')}
                </div>
            ` : ''}
            ${data.files && data.files.length ? `
                <div class="panel">
                    <h3>Loaded Files</h3>
                    ${data.files.map(f => `<div style="font-size:0.8rem; padding:2px 0; color:var(--text2); word-break:break-all;">${escHtml(f)}</div>`).join('')}
                </div>
            ` : ''}
        `;
    } catch (err) {
        div.innerHTML = '<div style="color:var(--red);">Error: ' + escHtml(err.message) + '</div>';
    }
}

// ── Utilities ────────────────────────────────────────────────
function escHtml(s) {
    if (!s) return '';
    const d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
}

// Enter key triggers search
document.getElementById('searchQuery').addEventListener('keydown', e => { if (e.key === 'Enter') doSearch(); });
document.getElementById('synLookup').addEventListener('keydown', e => { if (e.key === 'Enter') lookupSynonym(); });

// Load custom synonyms on page load
loadCustomSynonyms();
</script>

</body>
</html>
<?php
}
