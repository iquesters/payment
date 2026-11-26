{{--
    Record component "payment.summary": one row of the Payments list. The order, a
    coloured status badge, who paid, the amount and UTR, when; a rejected one with its
    reason; a later attempt for the same order marked. Plain HTML with __TOKENS__ filled
    in the browser from the row (see user-interface docs/record-components.md).
--}}
<div class="d-grid gap-1 small">
    <div class="d-flex justify-content-between align-items-start gap-2">
        <span class="fw-semibold font-monospace">__REF__</span>
        <span class="d-flex flex-wrap gap-1 justify-content-end">__ATTEMPT__ __STATUS__</span>
    </div>
    <div class="text-truncate">__BUYER__</div>
    <div class="d-flex justify-content-between gap-2 text-muted">
        <span>__AMOUNT__ · __KIND__ __UTR__</span>
        <span class="text-nowrap">__SENT__</span>
    </div>
    __MISMATCH__
    __REASON__
</div>
<script type="application/json" data-component-bindings>
{
    "__REF__": {"type": "text", "paths": ["reference"], "fallback": "—"},
    "__BUYER__": {"type": "text", "paths": ["buyer"], "fallback": ""},
    "__AMOUNT__": {"type": "text", "paths": ["amount_label"], "fallback": ""},
    "__KIND__": {"type": "text", "paths": ["kind"], "fallback": ""},
    "__SENT__": {"type": "text", "paths": ["submitted_at_label", "submitted_at"], "fallback": ""},
    "__UTR__": {
        "type": "template",
        "fields": {"utr": {"paths": ["utr"]}},
        "condition": "utr",
        "template": "· UTR <span class=\"font-monospace\">__UTR__</span>",
        "fallback_template": ""
    },
    "__STATUS__": {
        "type": "template",
        "fields": {"label": {"paths": ["status_label"]}, "tone": {"paths": ["status_tone"], "fallback": "secondary"}},
        "condition": "label",
        "template": "<span class=\"badge text-bg-__TONE__\">__LABEL__</span>",
        "fallback_template": ""
    },
    "__ATTEMPT__": {
        "type": "template",
        "fields": {"attempt": {"paths": ["attempt_label"]}},
        "condition": "attempt",
        "template": "<span class=\"badge text-bg-light border\">__ATTEMPT__</span>",
        "fallback_template": ""
    },
    "__MISMATCH__": {
        "type": "template",
        "fields": {"note": {"paths": ["mismatch_label"]}},
        "condition": "note",
        "template": "<div class=\"text-danger-emphasis\"><i class=\"fas fa-triangle-exclamation me-1\"></i>__NOTE__</div>",
        "fallback_template": ""
    },
    "__REASON__": {
        "type": "template",
        "fields": {"reason": {"paths": ["reason"]}},
        "condition": "reason",
        "template": "<div class=\"text-danger-emphasis\">Rejected: __REASON__</div>",
        "fallback_template": ""
    }
}
</script>
