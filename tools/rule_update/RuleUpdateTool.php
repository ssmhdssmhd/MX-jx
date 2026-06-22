<?php
/**
 * 规则自动更新工具 - 智能更新广告规则库
 *
 * 功能特点：
 * 1. 从在线源获取最新广告规则
 * 2. 自动合并到本地规则库
 * 3. 智能去重和分类
 * 4. 备份本地规则
 * 5. 回滚功能
 *
 * @author MX-射手沫蝴蝶
 * @version 1.0.0
 */

if (!class_exists('AbstractTool', false)) {
    require_once __DIR__ . '/../core/AbstractTool.php';
}

class RuleUpdateTool extends AbstractTool
{
    public $id = 'rule_update';
    public $group = '规则管理';

    /** 规则源URL列表 */
    private $ruleSources = [
        'https://raw.githubusercontent.com/privacy-protection-tools/anti-ad/master/anti-ad-domains.txt',
        'https://raw.githubusercontent.com/AdAway/adaway.github.io/master/hosts.txt',
    ];

    /** 本地规则文件路径 */
    private $localRuleFile;

    /** 备份目录 */
    private $backupDir;

    public function __construct() {
        $this->id = 'rule_update';
        $this->group = '规则管理';
        $this->localRuleFile = __DIR__ . '/../../config/global_rules.php';
        $this->backupDir = __DIR__ . '/../../cache/rule_backups';
    }

    public function name() {
        return '🔄 规则自动更新';
    }

    public function description() {
        return '从在线源自动更新广告规则库，智能去重合并，支持备份和回滚。';
    }

    public function version() {
        return '1.0.0';
    }

    public function getParamSchema() {
        return [
            'action' => [
                'type' => 'select',
                'label' => '操作',
                'options' => [
                    ['value' => 'check', 'label' => '检查更新'],
                    ['value' => 'update', 'label' => '立即更新'],
                    ['value' => 'backup', 'label' => '备份当前规则'],
                    ['value' => 'restore', 'label' => '回滚到备份'],
                    ['value' => 'stats', 'label' => '规则统计'],
                ],
                'default' => 'check',
            ],
            'source' => [
                'type' => 'select',
                'label' => '规则源',
                'options' => [
                    ['value' => 'all', 'label' => '所有源'],
                    ['value' => 'github', 'label' => '仅GitHub源'],
                ],
                'default' => 'all',
            ],
        ];
    }

    public function run($params = []) {
        $action = $params['action'] ?? 'check';
        $source = $params['source'] ?? 'all';

        switch ($action) {
            case 'check':
                return $this->checkUpdates($source);
            case 'update':
                return $this->performUpdate($source);
            case 'backup':
                return $this->backupRules();
            case 'restore':
                return $this->restoreRules();
            case 'stats':
                return $this->getStats();
            default:
                return $this->checkUpdates($source);
        }
    }

    /**
     * 检查更新
     */
    private function checkUpdates($source) {
        $sources = $source === 'all' ? $this->ruleSources : [$this->ruleSources[0]];
        $availableRules = [];
        $errors = [];

        foreach ($sources as $url) {
            $rules = $this->fetchRules($url);
            if ($rules !== false) {
                $availableRules[$url] = [
                    'count' => count($rules),
                    'rules' => $rules,
                ];
            } else {
                $errors[] = "无法获取: " . basename($url);
            }
        }

        $localRules = $this->getLocalRules();
        $totalAvailable = array_sum(array_column($availableRules, 'count'));
        $totalLocal = $this->countLocalRules($localRules);

        $newRules = [];
        foreach ($availableRules as $sourceUrl => $data) {
            $new = array_diff($data['rules'], $localRules);
            if (!empty($new)) {
                $newRules = array_merge($newRules, $new);
            }
        }

        return [
            'success' => true,
            'message' => sprintf(
                '检查完成：在线可用 %d 条规则，本地已有 %d 条，可更新 %d 条新规则',
                $totalAvailable, $totalLocal, count($newRules)
            ),
            'data' => [
                'available_rules' => $totalAvailable,
                'local_rules' => $totalLocal,
                'new_rules' => count($newRules),
                'sources_checked' => count($availableRules),
                'errors' => $errors,
                'can_update' => count($newRules) > 0,
            ],
        ];
    }

    /**
     * 执行更新
     */
    private function performUpdate($source) {
        // 先备份
        $backupResult = $this->backupRules();
        if (!$backupResult['success']) {
            return $backupResult;
        }

        $sources = $source === 'all' ? $this->ruleSources : [$this->ruleSources[0]];
        $allRules = [];
        $errors = [];

        foreach ($sources as $url) {
            $rules = $this->fetchRules($url);
            if ($rules !== false) {
                $allRules = array_merge($allRules, $rules);
            } else {
                $errors[] = "获取失败: " . basename($url);
            }
        }

        // 去重
        $allRules = array_unique($allRules);
        $allRules = array_filter($allRules, function($rule) {
            return !empty(trim($rule)) && strlen($rule) > 3;
        });

        // 分类规则
        $classified = $this->classifyRules($allRules);

        // 合并到本地规则
        $localRules = $this->getLocalRules();
        $mergedDomains = array_unique(array_merge($localRules['ad_domains'] ?? [], $classified['ad_domains']));
        $mergedKeywords = array_unique(array_merge($localRules['ad_keywords'] ?? [], $classified['ad_keywords']));

        // 保存新规则
        $newRules = [
            'ad_domains' => array_values($mergedDomains),
            'ad_keywords' => array_values($mergedKeywords),
            'whitelist' => $localRules['whitelist'] ?? [],
            'md5_signatures' => $localRules['md5_signatures'] ?? [],
            'updated_at' => date('Y-m-d H:i:s'),
            'source' => 'auto_updated',
        ];

        $result = $this->saveRules($newRules);

        if ($result) {
            return [
                'success' => true,
                'message' => sprintf(
                    '规则更新完成：新增 %d 条域名规则，%d 条关键词规则',
                    count($classified['ad_domains']),
                    count($classified['ad_keywords'])
                ),
                'data' => [
                    'total_domains' => count($mergedDomains),
                    'total_keywords' => count($mergedKeywords),
                    'backup_file' => $backupResult['data']['backup_file'] ?? null,
                    'errors' => $errors,
                ],
            ];
        } else {
            return [
                'success' => false,
                'message' => '保存规则失败，请检查文件权限',
                'data' => null,
            ];
        }
    }

    /**
     * 备份规则
     */
    private function backupRules() {
        if (!is_dir($this->backupDir)) {
            mkdir($this->backupDir, 0755, true);
        }

        $localRules = $this->getLocalRules();
        $filename = $this->backupDir . '/backup_' . date('Ymd_His') . '.json';

        $backupData = [
            'rules' => $localRules,
            'created_at' => date('Y-m-d H:i:s'),
            'version' => '1.0',
        ];

        if (file_put_contents($filename, json_encode($backupData, JSON_PRETTY_PRINT))) {
            return [
                'success' => true,
                'message' => '规则备份成功',
                'data' => [
                    'backup_file' => basename($filename),
                    'backup_path' => $filename,
                ],
            ];
        } else {
            return [
                'success' => false,
                'message' => '备份失败，请检查目录权限',
                'data' => null,
            ];
        }
    }

    /**
     * 恢复规则
     */
    private function restoreRules() {
        // 获取最新备份
        $backups = glob($this->backupDir . '/backup_*.json');
        if (empty($backups)) {
            return [
                'success' => false,
                'message' => '没有找到备份文件',
                'data' => null,
            ];
        }

        $latestBackup = end($backups);
        $backupContent = file_get_contents($latestBackup);
        $backupData = json_decode($backupContent, true);

        if (!$backupData || empty($backupData['rules'])) {
            return [
                'success' => false,
                'message' => '备份文件格式错误',
                'data' => null,
            ];
        }

        $result = $this->saveRules($backupData['rules']);
        if ($result) {
            return [
                'success' => true,
                'message' => '规则已恢复到: ' . basename($latestBackup),
                'data' => [
                    'restored_from' => basename($latestBackup),
                    'created_at' => $backupData['created_at'] ?? '未知',
                ],
            ];
        } else {
            return [
                'success' => false,
                'message' => '恢复失败，请检查文件权限',
                'data' => null,
            ];
        }
    }

    /**
     * 获取统计
     */
    private function getStats() {
        $localRules = $this->getLocalRules();
        $backups = glob($this->backupDir . '/backup_*.json');

        return [
            'success' => true,
            'message' => sprintf('本地规则统计完成，共有 %d 个备份', count($backups)),
            'data' => [
                'ad_domains' => count($localRules['ad_domains'] ?? []),
                'ad_keywords' => count($localRules['ad_keywords'] ?? []),
                'whitelist' => count($localRules['whitelist'] ?? []),
                'md5_signatures' => count($localRules['md5_signatures'] ?? []),
                'total_rules' => $this->countLocalRules($localRules),
                'backups_count' => count($backups),
                'latest_backup' => !empty($backups) ? basename(end($backups)) : null,
                'last_updated' => $localRules['updated_at'] ?? '从未更新',
            ],
        ];
    }

    /**
     * 获取本地规则
     */
    private function getLocalRules() {
        if (file_exists($this->localRuleFile)) {
            return require $this->localRuleFile;
        }
        return [
            'ad_domains' => [],
            'ad_keywords' => [],
            'whitelist' => [],
            'md5_signatures' => [],
        ];
    }

    /**
     * 保存规则
     */
    private function saveRules($rules) {
        $content = "<?php\n";
        $content .= "/**\n";
        $content .= " * 全局规则库 - 自动生成\n";
        $content .= " * 更新时间: " . date('Y-m-d H:i:s') . "\n";
        $content .= " */\n\n";
        $content .= "return [\n";

        foreach ($rules as $key => $value) {
            if (is_array($value)) {
                $content .= "    '" . addslashes($key) . "' => " . var_export($value, true) . ",\n";
            } else {
                $content .= "    '" . addslashes($key) . "' => '" . addslashes($value) . "',\n";
            }
        }

        $content .= "];\n";

        return file_put_contents($this->localRuleFile, $content);
    }

    /**
     * 统计本地规则数
     */
    private function countLocalRules($rules) {
        $count = 0;
        foreach (['ad_domains', 'ad_keywords', 'whitelist', 'md5_signatures'] as $key) {
            if (isset($rules[$key]) && is_array($rules[$key])) {
                $count += count($rules[$key]);
            }
        }
        return $count;
    }

    /**
     * 从URL获取规则
     */
    private function fetchRules($url) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        ]);

        $content = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($content && $httpCode === 200) {
            // 解析域名列表
            $lines = preg_split('/\r\n|\r|\n/', $content);
            $domains = [];

            foreach ($lines as $line) {
                $line = trim($line);
                // 跳过注释和空行
                if (empty($line) || strpos($line, '#') === 0) {
                    continue;
                }

                // 提取域名
                if (strpos($line, '0.0.0.0') !== false || strpos($line, '127.0.0.1') !== false) {
                    $parts = preg_split('/\s+/', $line);
                    if (count($parts) >= 2) {
                        $domains[] = strtolower(trim($parts[1]));
                    }
                } elseif (filter_var($line, FILTER_VALIDATE_DOMAIN)) {
                    $domains[] = strtolower($line);
                }
            }

            return array_unique($domains);
        }

        return false;
    }

    /**
     * 分类规则
     */
    private function classifyRules($rules) {
        $adDomains = [];
        $adKeywords = [];

        foreach ($rules as $rule) {
            $rule = trim($rule);
            if (empty($rule)) continue;

            // 广告相关域名
            if (preg_match('/^(ad[s]?|advert|adsystem|doubleclick|sponsor|promo)/i', $rule)) {
                $adDomains[] = $rule;
            } else {
                // 普通广告域名
                $adDomains[] = $rule;
            }
        }

        // 常见广告关键词
        $commonKeywords = ['ad_', '_ad', 'advert', 'sponsor', 'promo', 'banner', 'pre-roll', 'mid-roll'];

        return [
            'ad_domains' => $adDomains,
            'ad_keywords' => $commonKeywords,
        ];
    }
}
