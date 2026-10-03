<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application;

use App\Shared\Application\Errors\Refusal;
use App\Shared\Application\Security\PermissionChecker;
use App\Shared\Application\Tenancy\PropertyContext;
use App\Shared\Application\Tenancy\PropertyScopeViolation;
use App\Shared\Application\Time\Clock;
use App\Shared\Domain\Tenancy\PropertyId;

/**
 * The order of the dashboard cards and the ones a person hides (FR-DSH-017), kept per person and property. It changes only how the
 * cards are laid out for that person; which cards exist and who may see them stays with the dashboard service.
 */
final readonly class DashboardPreferenceService
{
    public const CARD_KEYS = ['occupancy', 'movements', 'activity', 'revenue', 'staff'];

    public function __construct(
        private DashboardPreferenceRepository $preferences,
        private PermissionChecker $permissions,
        private Clock $clock,
        private PropertyContext $property,
    ) {}

    /** @return array{order: list<string>, hidden: list<string>, saved: bool} the default order when nothing is saved */
    public function get(PropertyId $property, string $actorId): array
    {
        $this->authorize($property, $actorId);
        $saved = $this->preferences->find($property, strtolower($actorId));

        return $saved === null ? ['order' => self::CARD_KEYS, 'hidden' => [], 'saved' => false] : ['order' => $this->complete($saved['order']), 'hidden' => $saved['hidden'], 'saved' => true];
    }

    /**
     * @param  list<string>  $order  every card once, in the order wanted
     * @param  list<string>  $hidden  cards left out
     * @return array{order: list<string>, hidden: list<string>, saved: bool}
     */
    public function save(PropertyId $property, string $actorId, array $order, array $hidden): array
    {
        $this->authorize($property, $actorId);

        if (count($order) !== count(array_unique($order)) || array_diff($order, self::CARD_KEYS) !== [] || array_diff($hidden, self::CARD_KEYS) !== [] || count($hidden) !== count(array_unique($hidden))) {
            throw Refusal::invalid('Choose each card at most once, from the cards of the dashboard.', ['order', 'hidden']);
        }

        $order = $this->complete($order);

        if (count(array_diff($order, $hidden)) === 0) {
            throw Refusal::invalid('Keep at least one card on the dashboard.', ['hidden']);
        }

        $this->preferences->save($property, strtolower($actorId), $order, array_values($hidden), $this->clock->nowUtc());

        return ['order' => $order, 'hidden' => array_values($hidden), 'saved' => true];
    }

    /** Back to the default layout. @return array{order: list<string>, hidden: list<string>, saved: bool} */
    public function reset(PropertyId $property, string $actorId): array
    {
        $this->authorize($property, $actorId);
        $this->preferences->clear($property, strtolower($actorId));

        return $this->get($property, $actorId);
    }

    /** A card that is not in the list is put at the end, so a card added later still shows. @param list<string> $order @return list<string> */
    private function complete(array $order): array
    {
        return array_values(array_merge(array_values(array_intersect($order, self::CARD_KEYS)), array_diff(self::CARD_KEYS, $order)));
    }

    private function authorize(PropertyId $property, string $actorId): void
    {
        $current = $this->property->current();

        if (! $current->equals($property)) {
            throw PropertyScopeViolation::mismatched($current->toString(), $property->toString());
        }

        if (! $this->permissions->allowsInProperty($actorId, DashboardService::VIEW_PERMISSION, $property)) {
            throw Refusal::forbidden('This person may not see the dashboard.');
        }
    }
}
