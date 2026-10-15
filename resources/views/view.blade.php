{{-- Content fragment: Blueprint inserts it into its own admin layout. No
     @extends or @section here.
     Read-only screen. All the data comes from the controller
     (admin/controller.php, through Services\AdminOverviewService): the view
     never queries anything and only displays escaped values (double braces),
     including everything that comes from the journal, the database or the APIs
     (filenames chosen by users, mod names…). --}}

<div class="row">
    <div class="col-xs-12">
        <div class="box box-info">
            <div class="box-body">
                <p style="margin:0">
                    Read-only overview of the installed-mods detection: collisions in the mod reference,
                    journal of collisions, merges and conflicts, event statistics, and the current state
                    of the servers (computed on demand). Nothing on this page changes any data.
                </p>
            </div>
        </div>
    </div>
</div>

{{-- Referential collisions: normalized keys shared by several mods. --}}
<div class="row">
    <div class="col-xs-12">
        <div class="box box-primary">
            <div class="box-header with-border">
                <h3 class="box-title">Collisions ({{ count($collisions['items']) }}{{ $collisions['truncated'] ? '+' : '' }})</h3>
            </div>
            <div class="box-body">
                <p class="text-muted small">
                    Normalized file names shared by several mods of the same game: name-based detection
                    is inconclusive for these files (only hash verification can settle them).
                    @if ($collisions['truncated'])
                        Only the first {{ $limits['collision_keys'] }} keys are shown.
                    @endif
                </p>
                @if ($collisions['error'] !== null)
                    <div class="alert alert-danger">{{ $collisions['error'] }}</div>
                @elseif (count($collisions['items']) === 0)
                    <p class="text-muted">No collisions in the mod reference.</p>
                @else
                    <div class="table-responsive">
                        <table class="table table-condensed table-bordered">
                            <thead>
                                <tr><th>Game</th><th>Normalized key</th><th>Mods and known files</th></tr>
                            </thead>
                            <tbody>
                                @foreach ($collisions['items'] as $item)
                                    <tr>
                                        <td>{{ $item['game'] }}</td>
                                        <td><code>{{ $item['key'] }}</code><br><span class="text-muted small">{{ $item['nb_mods'] }} mods</span></td>
                                        <td>
                                            @foreach ($item['mods'] as $mod)
                                                <div style="margin-bottom:6px">
                                                    <strong>{{ $mod['name'] }}</strong>
                                                    <span class="text-muted small">#{{ $mod['id'] }}</span>
                                                    @if ($mod['modrinth_id'] !== null)
                                                        &middot;
                                                        @if ($mod['modrinth_url'] !== null)
                                                            <a href="{{ $mod['modrinth_url'] }}" target="_blank" rel="noopener noreferrer">Modrinth {{ $mod['modrinth_id'] }}</a>
                                                        @else
                                                            Modrinth {{ $mod['modrinth_id'] }}
                                                        @endif
                                                    @endif
                                                    @if ($mod['curseforge_id'] !== null)
                                                        &middot;
                                                        @if ($mod['curseforge_url'] !== null)
                                                            <a href="{{ $mod['curseforge_url'] }}" target="_blank" rel="noopener noreferrer">CurseForge {{ $mod['curseforge_id'] }}</a>
                                                        @else
                                                            CurseForge {{ $mod['curseforge_id'] }}
                                                        @endif
                                                    @endif
                                                    <ul class="small" style="margin:2px 0 0 0;padding-left:18px">
                                                        @foreach ($mod['files'] as $file)
                                                            <li><code>{{ $file['filename'] }}</code> <span class="text-muted">({{ $file['source'] }}, {{ number_format($file['size']) }} bytes)</span></li>
                                                        @endforeach
                                                        @if ($mod['more_files'] > 0)
                                                            <li class="text-muted">and {{ $mod['more_files'] }} more file(s)</li>
                                                        @endif
                                                    </ul>
                                                </div>
                                            @endforeach
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>

{{-- Journal (addonbrowser-collisions.log and its .1 archive), most recent first. --}}
<div class="row">
    <div class="col-xs-12">
        <div class="box">
            <div class="box-header with-border">
                <h3 class="box-title">Journal</h3>
                <div class="box-tools">
                    <a href="{{ $root }}" class="btn btn-xs {{ $journal['type'] === null ? 'btn-primary' : 'btn-default' }}">All</a>
                    @foreach ($journalTypes as $journalType)
                        <a href="{{ $root . '?type=' . urlencode($journalType) }}" class="btn btn-xs {{ $journal['type'] === $journalType ? 'btn-primary' : 'btn-default' }}">{{ $journalType }}</a>
                    @endforeach
                </div>
            </div>
            <div class="box-body">
                <p class="text-muted small">
                    Last {{ $limits['journal_lines'] }} lines at most (newest first), read from the end of the journal
                    and of its archive. A collision is noted at most once a day per server and key, a conflict
                    once a day per game, file and reason; merges are always noted.
                    @if ($journal['malformed'] > 0)
                        {{ $journal['malformed'] }} malformed line(s) skipped.
                    @endif
                </p>
                @if ($journal['error'] !== null)
                    <div class="alert alert-danger">{{ $journal['error'] }}</div>
                @elseif (count($journal['entries']) === 0)
                    <p class="text-muted">No journal entries{{ $journal['type'] !== null ? ' of this type' : '' }}.</p>
                @else
                    <div class="table-responsive">
                        {{-- No table-striped: with the panel's theme, odd rows got a white
                             background, making the light text unreadable. --}}
                        <table class="table table-condensed table-hover">
                            <thead>
                                <tr><th style="width:190px">Date</th><th style="width:90px">Type</th><th>Message</th></tr>
                            </thead>
                            <tbody>
                                @foreach ($journal['entries'] as $entry)
                                    <tr>
                                        <td><span class="small">{{ $entry['date'] }}</span></td>
                                        <td>
                                            <span class="label {{ $entry['type'] === 'merge' ? 'label-success' : ($entry['type'] === 'collision' ? 'label-warning' : 'label-danger') }}">{{ $entry['type'] }}</span>
                                        </td>
                                        <td>
                                            {{ $entry['message'] }}
                                            @if ($entry['context'] !== null)
                                                <details>
                                                    <summary class="small text-muted">Details</summary>
                                                    <pre class="small" style="max-height:300px;overflow:auto;white-space:pre-wrap;word-break:break-all">{{ $entry['context'] }}</pre>
                                                </details>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>

{{-- Event statistics (addonbrowser_stats table), per game. --}}
<div class="row">
    <div class="col-xs-12">
        <div class="box">
            <div class="box-header with-border"><h3 class="box-title">Statistics</h3></div>
            <div class="box-body">
                <p class="text-muted small">
                    Events counted once, where they happen: hash verification jobs (one per file),
                    and writes to the mod reference. Never counted when the Addons tab is opened.
                    7 days = since {{ $stats['since7'] }}, 30 days = since {{ $stats['since30'] }} (today included).
                </p>
                @if ($stats['error'] !== null)
                    <div class="alert alert-danger">{{ $stats['error'] }}</div>
                @elseif (count($stats['games']) === 0)
                    <p class="text-muted">No events counted yet.</p>
                @else
                    @foreach ($stats['games'] as $game => $metrics)
                        <h4>{{ $game }}</h4>
                        <div class="table-responsive">
                            <table class="table table-condensed table-hover" style="max-width:720px">
                                <thead>
                                    <tr><th>Metric</th><th class="text-right">7 days</th><th class="text-right">30 days</th><th class="text-right">All time</th></tr>
                                </thead>
                                <tbody>
                                    @foreach ($metrics as $row)
                                        <tr>
                                            <td><code>{{ $row['metric'] }}</code></td>
                                            <td class="text-right">{{ number_format($row['last7']) }}</td>
                                            <td class="text-right">{{ number_format($row['last30']) }}</td>
                                            <td class="text-right">{{ number_format($row['all']) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endforeach
                @endif
            </div>
        </div>
    </div>
</div>

{{-- Current state: computed on demand (POST form with a CSRF token), never stored. --}}
<div class="row">
    <div class="col-xs-12">
        <div class="box box-success">
            <div class="box-header with-border"><h3 class="box-title">Current state</h3></div>
            <div class="box-body">
                <p class="text-muted small">
                    For each server of a game with installed-mods detection: one listing of its mods folder,
                    then the detection by name and by the verification cache, read-only (no verification is
                    started, nothing is written). At most {{ $limits['max_servers'] }} servers and
                    {{ $limits['time_budget'] }} seconds per computation.
                </p>
                {{-- Button disabled on submit: a double click would send a second POST,
                     which answers "already running" and replaces the page of the first
                     computation (its result would be lost). --}}
                <form method="POST" action="{{ $root }}" style="margin-bottom:12px"
                      onsubmit="var b = this.querySelector('button'); b.disabled = true; b.textContent = 'Computing…';">
                    @csrf
                    @if ($journal['type'] !== null)
                        <input type="hidden" name="type" value="{{ $journal['type'] }}">
                    @endif
                    <button type="submit" class="btn btn-success btn-sm">Compute</button>
                </form>

                @if ($state === null)
                    <p class="text-muted">Not computed yet.</p>
                @elseif ($state['busy'])
                    <div class="alert alert-warning">A computation is already running. Try again in a minute.</div>
                @elseif ($state['error'] !== null)
                    <div class="alert alert-danger">{{ $state['error'] }}</div>
                @elseif (count($state['rows']) === 0)
                    <p class="text-muted">No servers running a game with installed-mods detection.</p>
                @else
                    <p class="small">
                        Computed at {{ $state['computed_at'] }} in {{ $state['seconds'] }} s
                        for {{ implode(', ', $state['games']) }}.
                        Servers: {{ $state['servers_total'] }}.
                        @if ($state['not_computed'] > 0)
                            Not computed: {{ $state['not_computed'] }} (server or time limit reached).
                        @endif
                    </p>
                    <div class="table-responsive">
                        <table class="table table-condensed table-hover">
                            <thead>
                                <tr>
                                    <th>Server</th><th>Node</th><th>Game</th>
                                    <th class="text-right">Files</th>
                                    <th class="text-right">By name</th>
                                    <th class="text-right">By cache</th>
                                    <th class="text-right">Collision</th>
                                    <th class="text-right">Unknown</th>
                                    <th class="text-right">Unverified (of unknown)</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($state['rows'] as $row)
                                    <tr>
                                        <td>{{ $row['name'] }} <span class="text-muted small">#{{ $row['id'] }}</span></td>
                                        <td>{{ $row['node'] }}</td>
                                        <td>{{ $row['game'] ?? '—' }}</td>
                                        @if ($row['counts'] !== null)
                                            <td class="text-right">{{ $row['counts']['files'] }}</td>
                                            <td class="text-right">{{ $row['counts']['name'] }}</td>
                                            <td class="text-right">{{ $row['counts']['cache'] }}</td>
                                            <td class="text-right">{{ $row['counts']['collision'] }}</td>
                                            <td class="text-right">{{ $row['counts']['unknown'] }}</td>
                                            <td class="text-right">{{ $row['counts']['unverified'] }}</td>
                                            <td>
                                                <span class="label label-success">OK</span>
                                                @if (! $row['cache_read'])
                                                    <span class="small text-muted">cache not read</span>
                                                @endif
                                            </td>
                                        @else
                                            <td colspan="6"></td>
                                            <td>
                                                <span class="label {{ $row['status'] === 'not_computed' ? 'label-default' : 'label-danger' }}">{{ $row['status'] === 'not_computed' ? 'Not computed' : 'Unavailable' }}</span>
                                                <span class="small">{{ $row['reason'] }}</span>
                                            </td>
                                        @endif
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot>
                                <tr>
                                    <th colspan="3">Total (computed servers)</th>
                                    <th class="text-right">{{ $state['totals']['files'] }}</th>
                                    <th class="text-right">{{ $state['totals']['name'] }}</th>
                                    <th class="text-right">{{ $state['totals']['cache'] }}</th>
                                    <th class="text-right">{{ $state['totals']['collision'] }}</th>
                                    <th class="text-right">{{ $state['totals']['unknown'] }}</th>
                                    <th class="text-right">{{ $state['totals']['unverified'] }}</th>
                                    <th></th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
