@props(['tone' => 'neutral'])

<span {{ $attributes->class(['rm-status', 'rm-status-'.$tone]) }}>{{ $slot }}</span>