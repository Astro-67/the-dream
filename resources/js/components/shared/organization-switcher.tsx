import { router, usePage } from '@inertiajs/react';
import { Building2, Check } from 'lucide-react';
import {
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu';
import { update } from '@/routes/current-organization';

/**
 * The organization choices in the sidebar's tenant menu, above the properties:
 * only the organizations the user belongs to (the server sends just those, and
 * rechecks membership on the switch). Hidden for a member of one organization,
 * whose organization the menu trigger already shows.
 */
export function OrganizationSwitcherItems() {
    const { tenant } = usePage().props;
    const currentId = tenant.organization?.id;

    if (tenant.organizations.length < 2) {
        return null;
    }

    const switchTo = (organizationId: string) => {
        if (organizationId !== currentId) {
            router.put(update(), { organization_id: organizationId });
        }
    };

    return (
        <>
            <DropdownMenuGroup>
                <DropdownMenuLabel className="text-xs font-normal text-muted-foreground">
                    Organizations
                </DropdownMenuLabel>
                {tenant.organizations.map((organization) => {
                    const isCurrent = organization.id === currentId;

                    return (
                        <DropdownMenuItem
                            key={organization.id}
                            onSelect={() => switchTo(organization.id)}
                            aria-current={isCurrent ? 'true' : undefined}
                            className="gap-2"
                            data-test="switch-organization"
                        >
                            <Building2
                                className="size-4 shrink-0 text-muted-foreground"
                                aria-hidden
                            />
                            <span className="min-w-0 flex-1 break-words">
                                {organization.name}
                            </span>
                            {isCurrent && (
                                <Check
                                    className="size-4 shrink-0"
                                    aria-hidden
                                />
                            )}
                            {isCurrent && (
                                <span className="sr-only">(current)</span>
                            )}
                        </DropdownMenuItem>
                    );
                })}
            </DropdownMenuGroup>
            <DropdownMenuSeparator />
        </>
    );
}
