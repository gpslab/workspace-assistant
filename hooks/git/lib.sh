# Shared by the hooks of this directory (sourced, not a hook itself). POSIX sh.
#
# The directory is set as core.hooksPath for the Claude process, which replaces the repository's own
# hooks. Each hook here therefore hands over to the repository's hook of the same name.

WA_HOOKS_DIR=$(cd "$(dirname "$0")" && pwd -P)
WA_ROOT=$(cd "$WA_HOOKS_DIR/../.." && pwd -P)

# Directory of the repository's own hooks: core.hooksPath of the LOCAL config (a relative one is taken
# from the directory the hook runs in), otherwise <common git dir>/hooks. Not `git rev-parse --git-path
# hooks`: with core.hooksPath set by the environment it answers with this very directory.
wa_repo_hooks_dir() {
    wa_path=$(git config --local --get core.hooksPath 2>/dev/null)
    if [ -n "$wa_path" ]; then
        case $wa_path in
            "~/"*) wa_path="$HOME/${wa_path#"~/"}" ;;
            /*) ;;
            *) wa_path="$PWD/$wa_path" ;;
        esac
    else
        wa_path=$(git rev-parse --git-common-dir 2>/dev/null) || return 1
        case $wa_path in
            /*) ;;
            *) wa_path="$PWD/$wa_path" ;;
        esac
        wa_path="$wa_path/hooks"
    fi
    printf '%s\n' "$wa_path"
}

# wa_run_repo_hook <name> [args...]: runs the repository's hook with the same stdin and arguments and
# returns its status; 0 when there is none, it is not executable, or it would be this directory.
wa_run_repo_hook() {
    wa_name=$1
    shift
    wa_dir=$(wa_repo_hooks_dir) || return 0
    wa_real=$(cd "$wa_dir" 2>/dev/null && pwd -P) || return 0
    [ "$wa_real" != "$WA_HOOKS_DIR" ] || return 0
    [ -f "$wa_real/$wa_name" ] && [ -x "$wa_real/$wa_name" ] || return 0
    "$wa_real/$wa_name" "$@"
}
