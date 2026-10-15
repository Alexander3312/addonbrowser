// The panel returns its errors as {"errors": [{"code","status","detail"}]}
// (see app/Exceptions/Handler.php::convertExceptionToArray in the panel), NOT
// {"message": "..."} — without this, the user would get a generic axios
// error.message ("Request failed with status code 429"), even for errors that
// do have a useful text on the server side (e.g. throttle: "Too Many Attempts.").
export const extractErrorMessage = (e: any): string => {
    return e.response?.data?.errors?.[0]?.detail || e.response?.data?.message || e.message || 'Unknown error';
};
