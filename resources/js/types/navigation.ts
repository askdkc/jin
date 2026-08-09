import type { LinkComponentBaseProps } from '@inertiajs/core';
import type { Component, SvelteComponent } from 'svelte';

type NavIcon =
    | Component<{ class?: string }>
    | (new (...args: any[]) => SvelteComponent<{ class?: string }>);

type BreadcrumbBase = {
    href: NonNullable<LinkComponentBaseProps['href']>;
};

export type BreadcrumbItem =
    | (BreadcrumbBase & {
          translationKey: string;
          title?: never;
      })
    | (BreadcrumbBase & {
          title: string;
          translationKey?: never;
      });

export type NavItem = {
    title: string;
    href: NonNullable<LinkComponentBaseProps['href']>;
    icon?: NavIcon;
    isActive?: boolean;
};

export type HeaderAction =
    | {
          type: 'link';
          label: string;
          href: NonNullable<LinkComponentBaseProps['href']>;
          variant?: 'default' | 'destructive';
      }
    | {
          type: 'form';
          label: string;
          action: string;
          method: 'post';
          variant?: 'default' | 'destructive';
          confirmMessage?: string;
      };
