{{-- Hidden until a .btn-delete trigger opens it; see resources/js/delete-modal.js.
     The uri prop is the resource segment the DELETE posts to, e.g. "os". --}}
<div id="confirmDeleteModal" class="modal-mask d-none" data-delete-uri="{{ $uri }}">
    <div class="modal">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header py-1">
                    <h4 class="modal-title js-modal-title" id="modal-title"></h4>
                </div>
                <div class="modal-body text-center">
                    Are you sure you want to delete this?
                    <form action="" method="POST">
                        @csrf
                        @method('DELETE')

                        <input type="hidden" id="id" name="id" value="" class="js-modal-id">
                        <div class="row mt-2">
                            <div class="col-6">
                                <button type="submit" title="delete"
                                        class="btn btn-danger px-3 py-1 mt-2 mt-2">
                                    Yes
                                </button>
                            </div>
                            <div class="col-6">

                                <button type="button" title="cancel" data-modal-dismiss
                                        class="btn btn-success px-3 py-1 mt-2 ms-4">
                                    No
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
