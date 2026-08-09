import type { Auth } from '@/types/auth';
import type { ChatUser, ProjectTool, WorkspaceBar } from '@/types/projects';
import type { Team } from '@/types/teams';

declare module 'trix';

// Extend ImportMeta interface for Vite...
declare module 'vite/client' {
    interface ImportMetaEnv {
        readonly VITE_APP_NAME: string;
        readonly VITE_REVERB_APP_KEY: string;
        readonly VITE_REVERB_HOST: string;
        readonly VITE_REVERB_PORT: string;
        readonly VITE_REVERB_SCHEME: string;
        [key: string]: string | boolean | undefined;
    }

    interface ImportMeta {
        readonly env: ImportMetaEnv;
        readonly glob: <T>(
            pattern: string,
            options?: { eager?: boolean },
        ) => Record<string, T>;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            locale: string;
            locales: string[];
            localeNames: Record<string, string>;
            translations: Record<string, string>;
            auth: Auth;
            sidebarOpen: boolean;
            currentTeam: Team | null;
            teams: Team[];
            notificationSummary: {
                unreadCount: number;
            };
            navigationProjects: ProjectTool[];
            navigationPeople?: ChatUser[];
            workspaceBar: WorkspaceBar;
            [key: string]: unknown;
        };
    }
}
