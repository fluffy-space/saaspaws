<?php

namespace SaasPaws\Ops\System\Components\Views;

use Viewi\Components\BaseComponent;

/** A dashboard card with a round icon (copied from Urlicer's Components\Views\Common). */
class IconCard extends BaseComponent
{
    public string $icon = 'bi-memory';
    public string $title = '';
    public string $iconClass = 'bg-primary';
}
