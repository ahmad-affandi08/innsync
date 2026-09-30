# Data Tables — TanStack Table Standard

Every operational table defines:

- typed column model;
- stable row ID;
- server-side pagination by default for unbounded data;
- server-side filtering/sorting for large/authoritative lists;
- URL-synchronized filters when a list should be shareable/bookmarkable;
- role-aware actions rendered only after server permission data, while backend re-authorizes;
- column visibility and density preferences where useful;
- empty, loading, error, and retry states;
- accessible headers and keyboard focus;
- export as a server job/endpoint when full dataset is required.

Never load tens of thousands of rows into the browser just to let TanStack paginate locally.
