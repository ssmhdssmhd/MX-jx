#!/bin/bash
# ============================================================
# 沫兮万能解析 - 自动更新脚本 v1.0
# ============================================================
# 功能：
#   1. 自动从 GitHub 拉取最新代码
#   2. 自动备份当前版本
#   3. 自动解压更新
#   4. 支持回滚到上一版本
#   5. 支持指定分支/版本
#   6. 支持国内镜像加速
# ============================================================

set -e

# ====================== 配置区 ======================

# GitHub 仓库地址
GITHUB_REPO="ssmhdssmhd/MX-jx"
GITHUB_BRANCH="trae/agent-gOpDZY"

# 国内镜像（GitHub 访问慢时自动使用）
GITHUB_MIRROR="https://ghproxy.com"
USE_MIRROR="auto"  # auto / yes / no

# 备份目录
BACKUP_DIR="./moxi_backups"

# 临时目录
TMP_DIR="./moxi_tmp"

# 版本文件
VERSION_FILE="./VERSION"

# 需要排除的文件/目录（更新时不覆盖）
EXCLUDE_FILES=(
    "config/"
    "data/"
    "*.db"
    "*.sqlite"
    "VERSION"
    "moxi_backups/"
    "moxi_tmp/"
    "update.sh"
    ".git/"
)

# ====================== 颜色定义 ======================

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

# ====================== 工具函数 ======================

info()    { echo -e "${BLUE}[INFO]${NC} $1"; }
success() { echo -e "${GREEN}[OK]${NC} $1"; }
warn()    { echo -e "${YELLOW}[WARN]${NC} $1"; }
error()   { echo -e "${RED}[ERROR]${NC} $1"; }
step()    { echo -e "\n${CYAN}==>${NC} ${CYAN}$1${NC}"; }

# ====================== 初始化 ======================

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

# ====================== 环境检测 ======================

check_env() {
    step "环境检测"

    local missing=0

    # 检测 curl / wget
    if command -v curl &> /dev/null; then
        DOWNLOAD_CMD="curl -L -o"
        success "curl: 可用"
    elif command -v wget &> /dev/null; then
        DOWNLOAD_CMD="wget -q -O"
        success "wget: 可用"
    else
        error "未找到 curl 或 wget，请先安装"
        missing=1
    fi

    # 检测 unzip
    if command -v unzip &> /dev/null; then
        success "unzip: 可用"
    else
        error "未找到 unzip，请先安装"
        missing=1
    fi

    # 检测 git（可选）
    if command -v git &> /dev/null; then
        success "git: 可用（备用方案）"
        HAS_GIT=1
    else
        warn "git: 未安装（将使用 zip 下载方式）"
        HAS_GIT=0
    fi

    # 检测 PHP（可选，用于语法检查）
    if command -v php &> /dev/null; then
        success "php: 可用（更新后自动检查语法）"
        HAS_PHP=1
    else
        warn "php: 未找到（跳过语法检查）"
        HAS_PHP=0
    fi

    if [ $missing -eq 1 ]; then
        error "环境检测失败，请安装缺少的依赖后重试"
        exit 1
    fi

    success "环境检测通过"
}

# ====================== 网络检测 ======================

check_network() {
    step "网络检测"

    if [ "$USE_MIRROR" = "yes" ]; then
        info "已配置强制使用镜像: $GITHUB_MIRROR"
        GITHUB_BASE="$GITHUB_MIRROR/https://github.com"
        return 0
    fi

    if [ "$USE_MIRROR" = "no" ]; then
        info "已配置不使用镜像"
        GITHUB_BASE="https://github.com"
        return 0
    fi

    # 自动检测：测试 GitHub 直连速度
    info "测试 GitHub 直连速度..."
    local start_time=$(date +%s%N)
    if curl -s --connect-timeout 5 --max-time 10 "https://github.com" > /dev/null 2>&1; then
        local end_time=$(date +%s%N)
        local elapsed=$(( (end_time - start_time) / 1000000 ))
        info "直连延迟: ${elapsed}ms"

        if [ $elapsed -gt 3000 ]; then
            warn "直连较慢，自动切换到镜像加速"
            GITHUB_BASE="$GITHUB_MIRROR/https://github.com"
        else
            success "直连正常"
            GITHUB_BASE="https://github.com"
        fi
    else
        warn "GitHub 直连失败，自动切换到镜像加速"
        GITHUB_BASE="$GITHUB_MIRROR/https://github.com"
    fi
}

# ====================== 获取当前版本 ======================

get_current_version() {
    if [ -f "$VERSION_FILE" ]; then
        CURRENT_VERSION=$(cat "$VERSION_FILE" | head -n 1 | tr -d '[:space:]')
    else
        CURRENT_VERSION="unknown"
    fi
    info "当前版本: $CURRENT_VERSION"
}

# ====================== 获取最新版本 ======================

get_latest_version() {
    step "获取最新版本信息"

    local api_url="https://api.github.com/repos/$GITHUB_REPO/commits/$GITHUB_BRANCH"
    local api_response=""

    info "正在查询最新版本..."

    if command -v curl &> /dev/null; then
        api_response=$(curl -s --connect-timeout 10 --max-time 15 "$api_url" 2>/dev/null || true)
    elif command -v wget &> /dev/null; then
        api_response=$(wget -q -O - --timeout=10 "$api_url" 2>/dev/null || true)
    fi

    if [ -z "$api_response" ]; then
        warn "无法获取版本信息，将下载最新代码"
        LATEST_VERSION="latest"
        LATEST_DATE=$(date '+%Y-%m-%d %H:%M:%S')
        return 0
    fi

    # 解析最新 commit
    LATEST_SHA=$(echo "$api_response" | grep -o '"sha": "[^"]*"' | head -1 | sed 's/"sha": "\(.*\)"/\1/' | cut -c1-7)
    LATEST_DATE=$(echo "$api_response" | grep -o '"date": "[^"]*"' | head -1 | sed 's/"date": "\(.*\)"/\1/')

    if [ -z "$LATEST_SHA" ]; then
        LATEST_VERSION="latest"
    else
        LATEST_VERSION="$GITHUB_BRANCH-$LATEST_SHA"
    fi

    success "最新版本: $LATEST_VERSION"
}

# ====================== 下载代码 ======================

download_code() {
    step "下载最新代码"

    # 创建临时目录
    rm -rf "$TMP_DIR"
    mkdir -p "$TMP_DIR"

    local zip_url="$GITHUB_BASE/$GITHUB_REPO/archive/refs/heads/$GITHUB_BRANCH.zip"
    local zip_file="$TMP_DIR/moxi_latest.zip"

    info "下载地址: $zip_url"
    info "正在下载..."

    if command -v curl &> /dev/null; then
        if ! curl -L --connect-timeout 10 --max-time 300 -o "$zip_file" "$zip_url"; then
            error "下载失败"
            return 1
        fi
    elif command -v wget &> /dev/null; then
        if ! wget -q --timeout=10 --tries=3 -O "$zip_file" "$zip_url"; then
            error "下载失败"
            return 1
        fi
    fi

    # 检查文件
    if [ ! -f "$zip_file" ] || [ ! -s "$zip_file" ]; then
        error "下载的文件为空"
        return 1
    fi

    local zip_size=$(du -h "$zip_file" | cut -f1)
    success "下载完成: $zip_size"

    # 解压
    info "正在解压..."
    if ! unzip -q "$zip_file" -d "$TMP_DIR"; then
        error "解压失败"
        return 1
    fi

    # 找到解压后的目录
    local extracted_dir=$(find "$TMP_DIR" -mindepth 1 -maxdepth 1 -type d | head -1)
    if [ -z "$extracted_dir" ]; then
        error "未找到解压后的目录"
        return 1
    fi

    EXTRACTED_DIR="$extracted_dir"
    success "解压完成"
}

# ====================== 备份当前版本 ======================

backup_current() {
    step "备份当前版本"

    mkdir -p "$BACKUP_DIR"

    local backup_name="backup_$(date '+%Y%m%d_%H%M%S')"
    local backup_path="$BACKUP_DIR/$backup_name"

    info "备份到: $backup_path"

    # 使用 tar 备份
    if command -v tar &> /dev/null; then
        local tar_cmd="tar -czf ${backup_path}.tar.gz"

        # 排除文件
        for exclude in "${EXCLUDE_FILES[@]}"; do
            tar_cmd="$tar_cmd --exclude='$exclude'"
        done

        tar_cmd="$tar_cmd ."

        if eval $tar_cmd 2>/dev/null; then
            local backup_size=$(du -h "${backup_path}.tar.gz" | cut -f1)
            success "备份完成: $backup_size"
            LAST_BACKUP="${backup_path}.tar.gz"
            return 0
        fi
    fi

    # 降级：直接复制目录
    mkdir -p "$backup_path"
    for item in ./*; do
        local basename_item=$(basename "$item")
        local skip=0

        for exclude in "${EXCLUDE_FILES[@]}"; do
            local exclude_base=$(echo "$exclude" | sed 's:/*$::')
            if [ "$basename_item" = "$exclude_base" ]; then
                skip=1
                break
            fi
        done

        if [ $skip -eq 0 ] && [ "$basename_item" != "moxi_tmp" ]; then
            cp -r "$item" "$backup_path/" 2>/dev/null || true
        fi
    done

    success "备份完成 (目录方式)"
    LAST_BACKUP="$backup_path"
}

# ====================== 执行更新 ======================

do_update() {
    step "执行更新"

    if [ -z "$EXTRACTED_DIR" ] || [ ! -d "$EXTRACTED_DIR" ]; then
        error "没有可更新的文件"
        return 1
    fi

    # 构建排除列表（rsync 方式或 cp 方式）
    if command -v rsync &> /dev/null; then
        # 使用 rsync 更高效
        info "使用 rsync 更新文件..."

        local exclude_params=""
        for exclude in "${EXCLUDE_FILES[@]}"; do
            exclude_params="$exclude_params --exclude='$exclude'"
        done

        eval "rsync -av --delete $exclude_params '$EXTRACTED_DIR/' ./" 2>&1 | tail -5 || true
    else
        # 降级：使用 cp 逐个复制
        info "使用 cp 更新文件..."

        # 复制新文件，排除指定文件
        for item in "$EXTRACTED_DIR"/*; do
            local basename_item=$(basename "$item")
            local skip=0

            for exclude in "${EXCLUDE_FILES[@]}"; do
                local exclude_base=$(echo "$exclude" | sed 's:/*$::')
                if [ "$basename_item" = "$exclude_base" ]; then
                    skip=1
                    break
                fi
            done

            if [ $skip -eq 0 ]; then
                rm -rf "./$basename_item"
                cp -r "$item" "./$basename_item"
            fi
        done
    fi

    # 更新版本号
    echo "$LATEST_VERSION" > "$VERSION_FILE"
    echo "更新时间: $(date '+%Y-%m-%d %H:%M:%S')" >> "$VERSION_FILE"

    success "文件更新完成"
}

# ====================== PHP 语法检查 ======================

check_php_syntax() {
    if [ $HAS_PHP -eq 0 ]; then
        return 0
    fi

    step "PHP 语法检查"

    local error_count=0
    local checked_count=0

    # 检查所有 PHP 文件
    while IFS= read -r -d '' php_file; do
        checked_count=$((checked_count + 1))
        if ! php -l "$php_file" > /dev/null 2>&1; then
            error "语法错误: $php_file"
            error_count=$((error_count + 1))
        fi
    done < <(find . -name "*.php" -type f -not -path "./moxi_*" -not -path "./config/*" -print0)

    info "已检查 $checked_count 个 PHP 文件"

    if [ $error_count -gt 0 ]; then
        warn "发现 $error_count 个语法错误，请检查"
        return 1
    else
        success "语法检查通过"
        return 0
    fi
}

# ====================== 清理临时文件 ======================

cleanup() {
    step "清理临时文件"
    rm -rf "$TMP_DIR"
    success "清理完成"
}

# ====================== 回滚功能 ======================

do_rollback() {
    step "回滚到上一版本"

    if [ ! -d "$BACKUP_DIR" ]; then
        error "没有找到备份目录"
        exit 1
    fi

    # 列出备份
    local backups=()
    if [ -n "$(ls -A "$BACKUP_DIR" 2>/dev/null)" ]; then
        for f in "$BACKUP_DIR"/*; do
            backups+=("$(basename "$f")")
        done
    fi

    if [ ${#backups[@]} -eq 0 ]; then
        error "没有可用的备份"
        exit 1
    fi

    # 排序（最新的在前）
    IFS=$'\n' sorted_backups=($(sort -r <<<"${backups[*]}")); unset IFS

    echo ""
    echo "可用的备份:"
    for i in "${!sorted_backups[@]}"; do
        echo "  [$i] ${sorted_backups[$i]}"
    done
    echo ""

    read -p "选择要回滚的版本编号 [0]: " choice
    choice=${choice:-0}

    if ! [[ "$choice" =~ ^[0-9]+$ ]] || [ $choice -ge ${#sorted_backups[@]} ]; then
        error "无效的选择"
        exit 1
    fi

    local selected_backup="$BACKUP_DIR/${sorted_backups[$choice]}"
    info "选择的备份: ${sorted_backups[$choice]}"

    read -p "确认回滚？此操作将覆盖当前文件 (y/N): " confirm
    if [ "$confirm" != "y" ] && [ "$confirm" != "Y" ]; then
        info "已取消"
        exit 0
    fi

    # 备份当前版本（回滚前也做个备份）
    backup_current

    # 恢复
    step "正在恢复..."

    if [[ "$selected_backup" == *.tar.gz ]]; then
        # tar 备份
        tar -xzf "$selected_backup" -C . 2>/dev/null || {
            error "恢复失败"
            exit 1
        }
    else
        # 目录备份
        for item in "$selected_backup"/*; do
            local basename_item=$(basename "$item")
            rm -rf "./$basename_item"
            cp -r "$item" "./$basename_item"
        done
    fi

    success "回滚完成"
}

# ====================== 查看版本 ======================

show_version() {
    get_current_version
    echo ""
    echo "  当前版本: $CURRENT_VERSION"
    echo "  脚本目录: $SCRIPT_DIR"
    echo ""
}

# ====================== 帮助信息 ======================

show_help() {
    cat << EOF

沫兮万能解析 - 自动更新脚本 v1.0

用法:
  $0 [命令] [选项]

命令:
  update       更新到最新版本（默认）
  rollback     回滚到上一版本
  version      查看当前版本
  check        仅检查更新，不执行更新
  help         显示此帮助信息

选项:
  -b, --branch <分支>    指定分支 (默认: $GITHUB_BRANCH)
  -m, --mirror <地址>    指定镜像地址
      --no-mirror        不使用镜像
  -f, --force            强制更新（即使已是最新版本）
  -h, --help             显示帮助信息

示例:
  $0 update                 # 更新到最新版本
  $0 update -b main         # 更新到 main 分支
  $0 rollback               # 回滚版本
  $0 version                # 查看版本

EOF
}

# ====================== 交互式菜单 ======================

show_menu() {
    # 只有在真实终端时才清屏
    if [ -t 0 ] && [ -t 1 ]; then
        clear
    fi
    echo ""
    echo -e "${GREEN}╔══════════════════════════════════════════════╗${NC}"
    echo -e "${GREEN}║         沫兮万能解析 - 自动更新工具          ║${NC}"
    echo -e "${GREEN}╚══════════════════════════════════════════════╝${NC}"
    echo ""

    get_current_version

    echo -e "  ${CYAN}当前版本:${NC} $CURRENT_VERSION"
    echo -e "  ${CYAN}脚本目录:${NC} $SCRIPT_DIR"
    echo ""
    echo -e "  ${YELLOW}请选择操作:${NC}"
    echo ""
    echo -e "  ${GREEN}[1]${NC} 更新到最新版本"
    echo -e "  ${GREEN}[2]${NC} 强制更新（忽略版本检查）"
    echo -e "  ${GREEN}[3]${NC} 检查更新"
    echo -e "  ${GREEN}[4]${NC} 回滚到上一版本"
    echo -e "  ${GREEN}[5]${NC} 查看当前版本"
    echo -e "  ${GREEN}[6]${NC} 切换更新分支"
    echo -e "  ${GREEN}[7]${NC} 切换镜像设置"
    echo -e "  ${GREEN}[8]${NC} 查看帮助信息"
    echo ""
    echo -e "  ${RED}[0]${NC} 退出"
    echo ""
    echo -e "════════════════════════════════════════════════"
}

interactive_menu() {
    while true; do
        show_menu

        read -p "  请输入数字选择 [1-8, 0退出]: " choice
        echo ""

        case "$choice" in
            1)
                info "开始更新到最新版本..."
                echo ""
                do_update_interactive
                pause_return
                ;;
            2)
                warn "强制更新模式..."
                echo ""
                force=1
                do_update_interactive
                force=0
                pause_return
                ;;
            3)
                info "检查更新..."
                echo ""
                check_env
                check_network
                get_current_version
                get_latest_version
                echo ""
                if [ "$CURRENT_VERSION" != "$LATEST_VERSION" ] && [ "$CURRENT_VERSION" != "unknown" ]; then
                    warn "发现新版本: $LATEST_VERSION"
                    info "可选择 [1] 进行更新"
                else
                    success "当前已是最新版本"
                fi
                pause_return
                ;;
            4)
                info "回滚版本..."
                echo ""
                check_env
                do_rollback
                pause_return
                ;;
            5)
                show_version
                pause_return
                ;;
            6)
                echo ""
                echo -e "  ${CYAN}当前分支:${NC} $GITHUB_BRANCH"
                echo ""
                read -p "  请输入分支名称: " new_branch
                if [ -n "$new_branch" ]; then
                    GITHUB_BRANCH="$new_branch"
                    success "分支已切换为: $GITHUB_BRANCH"
                else
                    warn "分支未修改"
                fi
                pause_return
                ;;
            7)
                echo ""
                echo -e "  ${CYAN}当前镜像设置:${NC} $USE_MIRROR"
                if [ "$USE_MIRROR" != "no" ]; then
                    echo -e "  ${CYAN}镜像地址:${NC} $GITHUB_MIRROR"
                fi
                echo ""
                echo "  请选择镜像模式:"
                echo ""
                echo "  [1] 自动检测（默认）"
                echo "  [2] 强制使用镜像"
                echo "  [3] 不使用镜像"
                echo ""
                read -p "  请选择 [1-3]: " mirror_choice
                case "$mirror_choice" in
                    1) USE_MIRROR="auto"; success "已设置为自动检测" ;;
                    2) USE_MIRROR="yes"; success "已设置为强制使用镜像" ;;
                    3) USE_MIRROR="no"; success "已设置为不使用镜像" ;;
                    *) warn "未修改设置" ;;
                esac
                pause_return
                ;;
            8)
                show_help
                pause_return
                ;;
            0)
                info "再见！"
                echo ""
                exit 0
                ;;
            *)
                error "无效的选择，请重新输入"
                sleep 1
                ;;
        esac
    done
}

# 交互式更新封装
do_update_interactive() {
    check_env
    check_network
    get_current_version
    get_latest_version

    if [ $force -eq 0 ] && [ "$CURRENT_VERSION" = "$LATEST_VERSION" ] && [ "$CURRENT_VERSION" != "unknown" ]; then
        echo ""
        success "当前已是最新版本！"
        echo ""
        return 0
    fi

    if ! download_code; then
        error "下载失败"
        cleanup
        return 1
    fi

    if [ -f "admin.php" ] || [ -f "index.php" ]; then
        backup_current
    else
        info "首次安装，跳过备份"
    fi

    do_update
    check_php_syntax || true
    cleanup

    echo ""
    success "更新完成！"
    echo ""
    echo "  当前版本: $LATEST_VERSION"
    echo "  更新时间: $(date '+%Y-%m-%d %H:%M:%S')"
    if [ -n "$LAST_BACKUP" ]; then
        echo "  备份文件: $LAST_BACKUP"
    fi
    echo ""
}

# 暂停等待用户按回车返回
pause_return() {
    echo ""
    read -p "按回车键返回主菜单..."
}

# ====================== 主流程 ======================

main() {
    # 如果没有参数，进入交互式菜单
    if [ $# -eq 0 ]; then
        interactive_menu
        exit 0
    fi

    local cmd="update"
    local force=0

    # 解析参数
    while [[ $# -gt 0 ]]; do
        case "$1" in
            update|rollback|version|check|help)
                cmd="$1"
                shift
                ;;
            -b|--branch)
                GITHUB_BRANCH="$2"
                shift 2
                ;;
            -m|--mirror)
                GITHUB_MIRROR="$2"
                USE_MIRROR="yes"
                shift 2
                ;;
            --no-mirror)
                USE_MIRROR="no"
                shift
                ;;
            -f|--force)
                force=1
                shift
                ;;
            -h|--help)
                show_help
                exit 0
                ;;
            *)
                error "未知参数: $1"
                show_help
                exit 1
                ;;
        esac
    done

    echo ""
    echo -e "${GREEN}============================================${NC}"
    echo -e "${GREEN}  沫兮万能解析 - 自动更新脚本${NC}"
    echo -e "${GREEN}============================================${NC}"
    echo ""

    case "$cmd" in
        version)
            show_version
            exit 0
            ;;
        help)
            show_help
            exit 0
            ;;
        rollback)
            check_env
            do_rollback
            cleanup
            echo ""
            success "回滚操作完成！"
            echo ""
            exit 0
            ;;
        check)
            check_env
            check_network
            get_current_version
            get_latest_version
            echo ""
            if [ "$CURRENT_VERSION" != "$LATEST_VERSION" ]; then
                info "发现新版本: $LATEST_VERSION"
                info "运行 $0 update 进行更新"
            else
                success "当前已是最新版本"
            fi
            echo ""
            exit 0
            ;;
        update)
            # 更新主流程
            check_env
            check_network
            get_current_version
            get_latest_version

            # 检查是否需要更新
            if [ $force -eq 0 ] && [ "$CURRENT_VERSION" = "$LATEST_VERSION" ] && [ "$CURRENT_VERSION" != "unknown" ]; then
                echo ""
                success "当前已是最新版本！"
                echo ""
                exit 0
            fi

            # 下载
            if ! download_code; then
                error "下载失败"
                cleanup
                exit 1
            fi

            # 备份
            if [ -f "admin.php" ] || [ -f "index.php" ]; then
                backup_current
            else
                info "首次安装，跳过备份"
            fi

            # 更新
            do_update

            # 语法检查
            check_php_syntax || true

            # 清理
            cleanup

            echo ""
            success "更新完成！"
            echo ""
            echo "  当前版本: $LATEST_VERSION"
            echo "  更新时间: $(date '+%Y-%m-%d %H:%M:%S')"
            if [ -n "$LAST_BACKUP" ]; then
                echo "  备份文件: $LAST_BACKUP"
            fi
            echo ""
            echo -e "${YELLOW}提示:${NC} 请刷新浏览器页面查看更新效果"
            echo ""
            exit 0
            ;;
        *)
            error "未知命令: $cmd"
            show_help
            exit 1
            ;;
    esac
}

# 执行主函数
main "$@"
