<?php

use Kirby\Cms\Section;

/**
 * Pending Requests Section
 *
 * Custom panel section to display and manage pending access requests
 * with approve/deny buttons.
 */
class PendingRequestsSection extends Section
{
    public function data(): array
    {
        $page = $this->model();
        $pending = $page->pending_requests()->toStructure();

        $requests = [];
        foreach ($pending as $request) {
            $requests[] = [
                'uuid' => $request->uuid()->value(),
                'ip' => $request->ip()->value(),
                'user_agent' => $request->user_agent()->value(),
                'requested_at' => $request->requested_at()->value(),
            ];
        }

        return [
            'requests' => $requests,
            'count' => count($requests),
        ];
    }
}
