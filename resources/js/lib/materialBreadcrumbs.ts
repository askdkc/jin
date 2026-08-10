import ProjectFileController from '@/actions/App/Http/Controllers/ProjectFileController';
import ProjectMaterialController from '@/actions/App/Http/Controllers/ProjectMaterialController';
import { show as showProject } from '@/routes/projects';
import type { BreadcrumbItem } from '@/types';

type BreadcrumbEntity = {
    id: number;
    name: string;
};

type MaterialBreadcrumbOptions = {
    teamSlug: string;
    project: BreadcrumbEntity;
    folder?: BreadcrumbEntity | null;
    file?: BreadcrumbEntity | null;
};

export const buildMaterialBreadcrumbs = ({
    teamSlug,
    project,
    folder = null,
    file = null,
}: MaterialBreadcrumbOptions): BreadcrumbItem[] => [
    {
        title: project.name,
        href: showProject([teamSlug, project.id]),
    },
    {
        translationKey: 'Materials',
        href: ProjectMaterialController.index([teamSlug, project.id]),
    },
    ...(folder
        ? [
              {
                  title: folder.name,
                  href: ProjectMaterialController.index(
                      [teamSlug, project.id],
                      { query: { folder: folder.id } },
                  ),
              } satisfies BreadcrumbItem,
          ]
        : []),
    ...(file
        ? [
              {
                  title: file.name,
                  href: ProjectFileController.show([
                      teamSlug,
                      project.id,
                      file.id,
                  ]),
              } satisfies BreadcrumbItem,
          ]
        : []),
];
