<section class="admin-panel" aria-labelledby="audit-log-title">
    <h2 id="audit-log-title">Administrative history</h2>
    @if ($auditLogs->isEmpty())
        <p class="admin-muted">No administrative actions have been recorded.</p>
    @else
        <table class="admin-table">
            <thead>
                <tr>
                    <th scope="col">When</th>
                    <th scope="col">Action</th>
                    <th scope="col">By</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($auditLogs as $auditLog)
                    <tr>
                        <td data-label="When">{{ $auditLog->created_at->format('M j, Y g:i A') }}</td>
                        <td data-label="Action">{{ $auditLog->action_label }}</td>
                        <td data-label="By">{{ $auditLog->actor->name }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</section>
