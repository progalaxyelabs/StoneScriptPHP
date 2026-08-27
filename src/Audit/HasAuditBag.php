<?php

declare(strict_types=1);

namespace StoneScriptPHP\Audit;

/**
 * Optional per-route enrichment for the audit trail (see the
 * `StoneScriptPHP\Audit` separate-audit-DB design, `AuditRecorder`). A route
 * handler that `use`s this trait gets a protected `$auditBag` it can populate
 * from inside `process()` (or `execute()` for typed handlers) — typically
 * right after reading the "old" value it's about to change, since that's the
 * one place the route already has both old and new in hand.
 *
 * Deliberately NOT a new required method on IRouteHandler: adding a method to
 * that interface would force every route handler in every consuming project
 * to implement it immediately (interfaces have no default method bodies in
 * PHP) just to keep compiling. Router::executeHandler() instead duck-types
 * via `method_exists($handler, 'auditBag')` — a handler that never uses this
 * trait is completely unaffected, a "default no-op, optional per route"
 * behavior.
 *
 * Usage:
 *
 *   final class UpdateInvoiceRoute implements IRouteHandler
 *   {
 *       use HasAuditBag;
 *
 *       public function process(): ApiResponse
 *       {
 *           $before = Database::fn('get_invoice', [$this->id]);
 *           // ... perform the update ...
 *           $this->auditRecord(
 *               entityType: 'invoice',
 *               entityId: (string) $this->id,
 *               oldValues: $before,
 *               newValues: ['status' => $this->status],
 *               summary: "Invoice #{$this->id} marked {$this->status}",
 *           );
 *           return res_ok(['id' => $this->id]);
 *       }
 *   }
 */
trait HasAuditBag
{
    /**
     * @var array{
     *   action?: ?string,
     *   entity_type?: ?string,
     *   entity_id?: ?string,
     *   old_values?: ?array,
     *   new_values?: ?array,
     *   summary?: ?string,
     * }|null
     */
    protected ?array $auditBag = null;

    /**
     * Populate the bag. Call this from inside process()/execute() once the
     * route knows what actually happened — safe to call more than once (the
     * last call before the handler returns wins); safe to never call at all
     * (AuditRecorder still writes the operation-level base record either
     * way — this only adds the enrichment).
     */
    protected function auditRecord(
        ?string $entityType = null,
        ?string $entityId = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $summary = null,
        ?string $action = null,
    ): void {
        $this->auditBag = [
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'summary' => $summary,
        ];
    }

    /**
     * Read back by Router::executeHandler() (via method_exists duck-typing,
     * not an interface) after process()/execute() completes.
     *
     * @return array{action?: ?string, entity_type?: ?string, entity_id?: ?string, old_values?: ?array, new_values?: ?array, summary?: ?string}|null
     */
    public function auditBag(): ?array
    {
        return $this->auditBag;
    }
}
