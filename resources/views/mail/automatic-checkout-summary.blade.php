<h1>{{ __('Automatic check-out summary') }}</h1>
<p>{{ $propertyName }} - {{ $date }}</p>
<h2>{{ __('Checked out') }}</h2>
<ul>
@forelse ($checkedOut as $number)
    <li>{{ $number }}</li>
@empty
    <li>{{ __('No stays checked out.') }}</li>
@endforelse
</ul>
<h2>{{ __('Skipped stays') }}</h2>
<ul>
@forelse ($skipped as $number => $reason)
    <li>{{ $number }}: {{ $reason }}</li>
@empty
    <li>{{ __('No stays skipped.') }}</li>
@endforelse
</ul>
