<?php

declare(strict_types=1);

namespace App\Domain\Tenants\Exceptions;

use DomainException;

/**
 * El usuario no es miembro del tenant (o el tenant activo apunta a un tenant
 * del que ya no es miembro). Se traduce a 404 en la capa HTTP para no revelar
 * la existencia del tenant.
 */
final class TenantMembershipException extends DomainException {}
