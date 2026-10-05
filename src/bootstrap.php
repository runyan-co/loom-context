<?php

declare(strict_types=1);

// PCRE2 10.49's JIT on ARM64 mishandles an alternation followed by a bounded repeat in UTF mode:
// '~(?:WEB|OPS)-\d{2,5}~u' does not find "OPS-431". That would silently drop entities, and nothing here is
// regex-bound, so the interpreter is used instead.
ini_set('pcre.jit', '0');
