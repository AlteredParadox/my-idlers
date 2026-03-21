@if ($message = Session::get('success'))
    <div class="alert alert-success" role="alert">
        <p class="my-1">{{ $message }}</p>
    </div>
@elseif($message = Session::get('error'))
    <div class="alert alert-danger" role="alert">
        <p class="my-1">{{ $message }}</p>
    </div>
@endif
@if ($errors->any())
    <div class="alert alert-danger" role="alert">
        <ul class="mb-0">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif
