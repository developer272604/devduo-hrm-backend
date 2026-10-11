<?php

namespace App\Domain\Experience\Models;

use App\Domain\Tenancy\{HasPublicId, TenantRecord};

class Ticket extends TenantRecord
{
    use HasPublicId;
    protected $table = 'experience_tickets';
    protected function casts(): array { return ['sla_due_at' => 'datetime', 'first_response_at' => 'datetime', 'resolved_at' => 'datetime', 'closed_at' => 'datetime']; }
    public function category() { return $this->belongsTo(ServiceCategory::class, 'category_id'); }
    public function comments() { return $this->hasMany(TicketComment::class, 'ticket_id')->orderBy('id'); }
    public function attachments() { return $this->hasMany(TicketAttachment::class, 'ticket_id')->orderBy('id'); }
    public function escalations() { return $this->hasMany(TicketEscalation::class, 'ticket_id')->orderBy('created_at'); }
}
