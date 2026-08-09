import type { I18n } from 'emoji-picker-element/shared';

export type EmojiPickerLocaleKey =
    | 'en'
    | 'ja'
    | 'fr'
    | 'de'
    | 'zh_CN'
    | 'zh_TW'
    | 'es'
    | 'pt_BR'
    | 'pt_PT'
    | 'ko';

export type EmojiPickerLocalization = {
    locale: string;
    i18n: I18n;
    dataSource: string;
};

const traditionalChineseI18n: I18n = {
    categoriesLabel: '類別',
    emojiUnsupportedMessage: '您的瀏覽器不支援彩色表情符號。',
    favoritesLabel: '我的最愛',
    loadingMessage: '載入中…',
    networkErrorMessage: '無法載入表情符號。',
    regionLabel: '表情符號選擇器',
    searchDescription: '有搜尋結果時，按上/下方向鍵選擇，按 Enter 鍵確認。',
    searchLabel: '搜尋',
    searchResultsLabel: '搜尋結果',
    skinToneDescription: '展開後，按上/下方向鍵選擇，按 Enter 鍵確認。',
    skinToneLabel: '選擇膚色（目前為 {skinTone}）',
    skinTonesLabel: '膚色',
    skinTones: ['預設', '淺色', '中淺色', '中等', '中深色', '深色'],
    categories: {
        custom: '自訂',
        'smileys-emotion': '表情與情緒',
        'people-body': '人物與身體',
        'animals-nature': '動物與自然',
        'food-drink': '食物與飲料',
        'travel-places': '旅遊與地點',
        activities: '活動',
        objects: '物件',
        symbols: '符號',
        flags: '旗幟',
    },
};

const koreanI18n: I18n = {
    categoriesLabel: '카테고리',
    emojiUnsupportedMessage: '브라우저에서 컬러 이모지를 지원하지 않습니다.',
    favoritesLabel: '즐겨찾기',
    loadingMessage: '로드 중…',
    networkErrorMessage: '이모지를 불러올 수 없습니다.',
    regionLabel: '이모지 선택기',
    searchDescription:
        '검색 결과가 표시되면 위/아래 화살표 키로 선택하고 Enter 키로 확정하세요.',
    searchLabel: '검색',
    searchResultsLabel: '검색 결과',
    skinToneDescription:
        '펼친 뒤 위/아래 화살표 키로 선택하고 Enter 키로 확정하세요.',
    skinToneLabel: '피부색 선택(현재 {skinTone})',
    skinTonesLabel: '피부색',
    skinTones: ['기본', '밝음', '약간 밝음', '보통', '약간 어두움', '어두움'],
    categories: {
        custom: '사용자 지정',
        'smileys-emotion': '스마일리 및 감정',
        'people-body': '사람 및 신체',
        'animals-nature': '동물 및 자연',
        'food-drink': '음식 및 음료',
        'travel-places': '여행 및 장소',
        activities: '활동',
        objects: '사물',
        symbols: '기호',
        flags: '깃발',
    },
};

export function emojiPickerLocaleKey(locale?: string): EmojiPickerLocaleKey {
    const normalized = (locale ?? 'en').replaceAll('_', '-').toLowerCase();

    if (normalized === 'zh-tw' || normalized.startsWith('zh-hant')) {
        return 'zh_TW';
    }

    if (normalized.startsWith('zh')) {
        return 'zh_CN';
    }

    if (normalized === 'pt-br') {
        return 'pt_BR';
    }

    if (normalized.startsWith('pt')) {
        return 'pt_PT';
    }

    const language = normalized.split('-')[0];

    return ['ja', 'fr', 'de', 'es', 'ko'].includes(language)
        ? (language as EmojiPickerLocaleKey)
        : 'en';
}

export async function loadEmojiPickerLocalization(
    locale?: string,
): Promise<EmojiPickerLocalization> {
    switch (emojiPickerLocaleKey(locale)) {
        case 'ja': {
            const [i18n, dataSource] = await Promise.all([
                import('emoji-picker-element/i18n/ja.js'),
                import('emoji-picker-element-data/ja/cldr-native/data.json?url'),
            ]);

            return {
                locale: 'ja',
                i18n: i18n.default,
                dataSource: dataSource.default,
            };
        }
        case 'fr': {
            const [i18n, dataSource] = await Promise.all([
                import('emoji-picker-element/i18n/fr.js'),
                import('emoji-picker-element-data/fr/cldr-native/data.json?url'),
            ]);

            return {
                locale: 'fr',
                i18n: i18n.default,
                dataSource: dataSource.default,
            };
        }
        case 'de': {
            const [i18n, dataSource] = await Promise.all([
                import('emoji-picker-element/i18n/de.js'),
                import('emoji-picker-element-data/de/cldr-native/data.json?url'),
            ]);

            return {
                locale: 'de',
                i18n: i18n.default,
                dataSource: dataSource.default,
            };
        }
        case 'zh_CN': {
            const [i18n, dataSource] = await Promise.all([
                import('emoji-picker-element/i18n/zh_CN.js'),
                import('emoji-picker-element-data/zh/cldr-native/data.json?url'),
            ]);

            return {
                locale: 'zh',
                i18n: i18n.default,
                dataSource: dataSource.default,
            };
        }
        case 'zh_TW': {
            const dataSource =
                await import('emoji-picker-element-data/zh-hant/cldr-native/data.json?url');

            return {
                locale: 'zh-Hant',
                i18n: traditionalChineseI18n,
                dataSource: dataSource.default,
            };
        }
        case 'es': {
            const [i18n, dataSource] = await Promise.all([
                import('emoji-picker-element/i18n/es.js'),
                import('emoji-picker-element-data/es/cldr-native/data.json?url'),
            ]);

            return {
                locale: 'es',
                i18n: i18n.default,
                dataSource: dataSource.default,
            };
        }
        case 'pt_BR': {
            const [i18n, dataSource] = await Promise.all([
                import('emoji-picker-element/i18n/pt_BR.js'),
                import('emoji-picker-element-data/pt/cldr-native/data.json?url'),
            ]);

            return {
                locale: 'pt-BR',
                i18n: i18n.default,
                dataSource: dataSource.default,
            };
        }
        case 'pt_PT': {
            const [i18n, dataSource] = await Promise.all([
                import('emoji-picker-element/i18n/pt_PT.js'),
                import('emoji-picker-element-data/pt/cldr-native/data.json?url'),
            ]);

            return {
                locale: 'pt-PT',
                i18n: i18n.default,
                dataSource: dataSource.default,
            };
        }
        case 'ko': {
            const dataSource =
                await import('emoji-picker-element-data/ko/cldr-native/data.json?url');

            return {
                locale: 'ko',
                i18n: koreanI18n,
                dataSource: dataSource.default,
            };
        }
        default: {
            const [i18n, dataSource] = await Promise.all([
                import('emoji-picker-element/i18n/en.js'),
                import('emoji-picker-element-data/en/cldr-native/data.json?url'),
            ]);

            return {
                locale: 'en',
                i18n: i18n.default,
                dataSource: dataSource.default,
            };
        }
    }
}

export function fileViewerLocale(locale?: string): 'zh-CN' | 'en-US' {
    return (locale ?? '').replaceAll('_', '-').toLowerCase().startsWith('zh-cn')
        ? 'zh-CN'
        : 'en-US';
}
