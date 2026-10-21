import { Link } from '@inertiajs/react';
import { ChevronDown, Ellipsis } from 'lucide-react';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import type { NavItem } from '@/types';

export function NavMain({
    items,
    label = 'Workspace',
    labelHref,
    collapsible = false,
}: {
    items: NavItem[];
    label?: string;
    labelHref?: NavItem['href'];
    collapsible?: boolean;
}) {
    const { isCurrentUrl } = useCurrentUrl();
    const { setOpenMobile } = useSidebar();

    if (items.length === 0) {
        return null;
    }

    const menu = (
        <SidebarMenu>
            {items.map((item) => (
                <SidebarMenuItem key={item.title}>
                    <SidebarMenuButton
                        asChild
                        isActive={isCurrentUrl(item.href)}
                        tooltip={{ children: item.title }}
                    >
                        <Link
                            href={item.href}
                            prefetch
                            onClick={() => setOpenMobile(false)}
                        >
                            {item.icon && <item.icon />}
                            <span>{item.title}</span>
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            ))}
        </SidebarMenu>
    );

    return collapsible ? (
        <Collapsible defaultOpen={false} className="group/more" asChild>
            <SidebarGroup className="px-2 py-0">
                <CollapsibleTrigger asChild>
                    <SidebarMenuButton tooltip={label}>
                        <Ellipsis className="hidden group-data-[collapsible=icon]:block" />
                        <span>{label}</span>
                        <ChevronDown className="ml-auto size-4 transition-transform group-data-[state=open]/more:rotate-180" />
                    </SidebarMenuButton>
                </CollapsibleTrigger>
                <CollapsibleContent>{menu}</CollapsibleContent>
            </SidebarGroup>
        </Collapsible>
    ) : (
        <SidebarGroup className="px-2 py-0">
            {labelHref ? (
                <SidebarGroupLabel
                    asChild
                    className="group-data-[collapsible=icon]:hidden hover:bg-sidebar-accent hover:text-sidebar-accent-foreground"
                >
                    <Link href={labelHref} onClick={() => setOpenMobile(false)}>
                        {label}
                    </Link>
                </SidebarGroupLabel>
            ) : (
                <SidebarGroupLabel>{label}</SidebarGroupLabel>
            )}
            {menu}
        </SidebarGroup>
    );
}
