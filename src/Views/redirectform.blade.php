<html>
    <body>
        @php
        	$id=uniqid("pmb-redirect-form-");
        @endphp
        <form action="{{ $action }}" method="{{ $method }}" id="{{ $id }}">
            @foreach($fields as $name => $value)
            <input type="hidden" name="{{ $name }}" value="{{ $value }}">
            @endforeach
            <input type="submit" value="Se non vieni ridirezionato entro 5 secondi, fai click qui" style="border: 0;background-color:transparent;cursor:hand;color:blue;text-decoration:underline">            
        </form>
        <script>
            setTimeout(function () {
				document.getElementById("{{ $id }}").submit();
            }, 150);
        </script>
    </body>
</html>