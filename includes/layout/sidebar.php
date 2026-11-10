<aside class="sidebar" id="appSidebar" aria-label="<?php echo mh_h($topbar['aria']); ?>">
    <div class="sidebar-header">
        <?php echo $brand['mark']; ?>
        <div class="brand-text nav-text">
            <h2><?php echo mh_h($brand['name']); ?></h2>
            <p class="brand-subtitle"><?php echo mh_h($brand['subtitle']); ?></p>
        </div>
    </div>

    <nav class="sidebar-nav" aria-label="Primary">
        <ul>
            <?php foreach ($nav as $item): ?>
                <?php if (!empty($item['divider'])): ?>
                    <li class="nav-divider" role="separator"></li>
                <?php elseif (!empty($item['children'])): ?>
                    <li class="has-submenu<?php echo !empty($item['active']) ? ' open' : ''; ?>">
                        <a href="#" class="<?php echo !empty($item['active']) ? 'active' : ''; ?>" title="<?php echo mh_h($item['label']); ?>"
                           role="button" data-submenu-toggle="<?php echo mh_h($item['id']); ?>"
                           aria-expanded="<?php echo !empty($item['active']) ? 'true' : 'false'; ?>" aria-controls="<?php echo mh_h($item['id']); ?>">
                            <span class="nav-icon"><?php echo mh_icon($item['icon']); ?></span>
                            <span class="nav-text"><?php echo mh_h($item['label']); ?></span>
                            <span class="nav-caret"><?php echo mh_icon('chevron-down'); ?></span>
                        </a>
                        <ul id="<?php echo mh_h($item['id']); ?>" class="sidebar-submenu">
                            <?php foreach ($item['children'] as $child): ?>
                                <li><a href="<?php echo mh_h($child['href']); ?>" class="<?php echo !empty($child['active']) ? 'active' : ''; ?>"<?php echo !empty($child['active']) ? ' aria-current="page"' : ''; ?>><?php echo mh_h($child['label']); ?></a></li>
                            <?php endforeach; ?>
                        </ul>
                    </li>
                <?php else: ?>
                    <li><?php mh_layout_nav_link($item); ?></li>
                <?php endif; ?>
            <?php endforeach; ?>
        </ul>
    </nav>

    <div class="sidebar-footer">
        <?php if (!empty($user)): ?>
            <a class="sidebar-user" href="<?php echo mh_h($user['href']); ?>" title="My Profile">
                <span class="pp-avatar" aria-hidden="true"><?php echo mh_h($user['initials']); ?></span>
                <span class="nav-text sidebar-user-text">
                    <strong><?php echo mh_h($user['name']); ?></strong>
                    <small><?php echo mh_h($user['sub']); ?></small>
                </span>
            </a>
        <?php endif; ?>
        <?php foreach ($footer as $item): mh_layout_nav_link($item); endforeach; ?>
    </div>
</aside>
