import { router, usePage } from '@inertiajs/react';
import { Check, Hotel } from 'lucide-react';
import {
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
} from '@/components/ui/dropdown-menu';
import { StatusChip } from '@/components/ui/status-chip';
import { update } from '@/routes/current-property';

/**
 * The property choices in the sidebar's tenant menu: only the properties the
 * member may act in (the server sends just those, and rechecks on the switch).
 * The organization switcher sits next to it in the same menu.
 */
export function PropertySwitcherItems() {
    const { tenant } = usePage().props;
    const currentId = tenant.property?.id;

    const switchTo = (propertyId: string) => {
        if (propertyId !== currentId) {
            router.put(update(), { property_id: propertyId });
        }
    };

    return (
        <DropdownMenuGroup>
            <DropdownMenuLabel className="text-xs font-normal text-muted-foreground">
                Properties
            </DropdownMenuLabel>
            {tenant.properties.length === 0 && (
                <DropdownMenuItem disabled>
                    No properties you can work in
                </DropdownMenuItem>
            )}
            {tenant.properties.map((property) => {
                const isCurrent = property.id === currentId;

                return (
                    <DropdownMenuItem
                        key={property.id}
                        onSelect={() => switchTo(property.id)}
                        aria-current={isCurrent ? 'true' : undefined}
                        className="gap-2"
                        data-test="switch-property"
                    >
                        <Hotel
                            className="size-4 shrink-0 text-muted-foreground"
                            aria-hidden
                        />
                        <span className="min-w-0 flex-1 break-words">
                            {property.name}
                        </span>
                        {property.demo && (
                            <StatusChip tone="info">Demo</StatusChip>
                        )}
                        {isCurrent && (
                            <Check className="size-4 shrink-0" aria-hidden />
                        )}
                        {isCurrent && (
                            <span className="sr-only">(current)</span>
                        )}
                    </DropdownMenuItem>
                );
            })}
        </DropdownMenuGroup>
    );
}
